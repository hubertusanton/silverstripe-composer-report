<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Settings of the monitor. Env variables win over YAML where noted.
 */
class ServerSettings
{
    use Configurable;
    use Injectable;

    /**
     * Largest accepted request body, in bytes. Also limit the location in the web server.
     *
     * @config
     */
    private static int $max_body_bytes = 262144;

    /**
     * Reports per project per hour, counted from the database.
     *
     * @config
     */
    private static int $rate_limit_per_hour = 10;

    /**
     * Failed authentications per IP (IPv6 per /64) per hour.
     *
     * @config
     */
    private static int $auth_failure_limit_per_hour = 20;

    /**
     * Reports kept per project. Never lower than rate_limit_per_hour.
     *
     * @config
     */
    private static int $retention_count = 30;

    /**
     * A project without a (successful) report for this many days is stale.
     *
     * @config
     */
    private static int $stale_after_days = 3;

    /**
     * Default recipient; env COMPOSER_REPORT_NOTIFY_EMAIL wins, a project's NotifyEmail wins over both.
     *
     * @config
     */
    private static string $notify_email = '';

    /**
     * Sender; env COMPOSER_REPORT_NOTIFY_FROM wins, Email.admin_email is the fallback.
     *
     * @config
     */
    private static string $notify_from = '';

    /**
     * Attempts per notification before it is given up.
     *
     * @config
     */
    private static int $notify_max_attempts = 5;

    public function maxBodyBytes(): int
    {
        return max(1, (int) static::config()->get('max_body_bytes'));
    }

    public function rateLimitPerHour(): int
    {
        return max(1, (int) static::config()->get('rate_limit_per_hour'));
    }

    public function authFailureLimitPerHour(): int
    {
        return max(1, (int) static::config()->get('auth_failure_limit_per_hour'));
    }

    /**
     * The per-project rate limit counts the stored reports of the last hour,
     * so pruning below that limit would undo it.
     */
    public function retentionCount(): int
    {
        $retention = max(1, (int) static::config()->get('retention_count'));
        $rateLimit = $this->rateLimitPerHour();

        if ($retention < $rateLimit) {
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                'composer-report: retention_count (%d) is lower than rate_limit_per_hour (%d); using %d',
                $retention,
                $rateLimit,
                $rateLimit,
            ));

            return $rateLimit;
        }

        return $retention;
    }

    public function staleAfterDays(): int
    {
        return max(0, (int) static::config()->get('stale_after_days'));
    }

    /**
     * Default recipients in order of preference: env COMPOSER_REPORT_NOTIFY_EMAIL, then YAML
     * notify_email. Both are returned, so an invalid env value can fall back to the YAML one.
     *
     * @return list<string>
     */
    public function notifyEmailCandidates(): array
    {
        return array_values(array_filter([
            self::env('COMPOSER_REPORT_NOTIFY_EMAIL'),
            self::nonEmpty(static::config()->get('notify_email')),
        ], 'is_string'));
    }

    public function notifyFrom(): ?string
    {
        return self::env('COMPOSER_REPORT_NOTIFY_FROM') ?? self::nonEmpty(static::config()->get('notify_from'));
    }

    public function notifyMaxAttempts(): int
    {
        return max(1, (int) static::config()->get('notify_max_attempts'));
    }

    private static function env(string $name): ?string
    {
        $value = Environment::getEnv($name);

        return is_scalar($value) ? self::nonEmpty((string) $value) : null;
    }

    private static function nonEmpty(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
