<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Ingest;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Cache\RateLimiter;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * Two limits:
 * - failed requests before authentication, per IP (IPv6 per /64), in the cache;
 *   fails open when the cache does;
 * - reports per project per hour, counted from the database.
 */
class IngestRateLimiter
{
    use Injectable;

    private const DECAY_MINUTES = 60;

    public function isAuthFailureLimited(HTTPRequest $request): bool
    {
        try {
            return !$this->authLimiter($request)->canAccess();
        } catch (\Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }
    }

    public function recordAuthFailure(HTTPRequest $request): void
    {
        try {
            $this->authLimiter($request)->hit();
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Null when the project may report, otherwise the seconds until it may again.
     */
    public function projectRetryAfter(ComposerReportProject $project): ?int
    {
        $now = DBDatetime::now()->getTimestamp();
        $recent = ComposerReport::get()->filter([
            'ProjectID'              => $project->ID,
            'ReceivedAt:GreaterThan' => date('Y-m-d H:i:s', $now - 3600),
        ]);

        if ($recent->count() < ServerSettings::singleton()->rateLimitPerHour()) {
            return null;
        }

        $oldest = $recent->sort(['ReceivedAt' => 'ASC', 'ID' => 'ASC'])->first();
        $oldestAt = $oldest instanceof ComposerReport ? (int) strtotime($oldest->ReceivedAt) : $now;

        return max(1, $oldestAt + 3600 - $now);
    }

    /**
     * IPv4 as is, IPv6 reduced to its /64 network.
     */
    public static function clientKey(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16) {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return $ip;
    }

    private function authLimiter(HTTPRequest $request): RateLimiter
    {
        // Cache keys may not contain ':' and other reserved characters
        $identifier = 'composerreport-auth-' . sha1(self::clientKey((string) $request->getIP()));

        return RateLimiter::create(
            $identifier,
            ServerSettings::singleton()->authFailureLimitPerHour(),
            self::DECAY_MINUTES,
        );
    }

    private function logFailure(\Throwable $exception): void
    {
        Injector::inst()->get(LoggerInterface::class)->warning(
            'composer-report: auth failure rate limiter unavailable, failing open: ' . $exception->getMessage(),
        );
    }
}
