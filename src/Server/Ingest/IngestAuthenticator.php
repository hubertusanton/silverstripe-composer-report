<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Ingest;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Core\Security\TokenHasher;
use Webium\ComposerReport\Server\Model\ComposerReportProject;

/**
 * Authenticates an ingest request from its headers, before the body is parsed:
 * X-Composer-Report-Project names the project, Authorization carries the token.
 * Every failure looks the same to the caller.
 */
class IngestAuthenticator
{
    use Injectable;

    public const PROJECT_HEADER = 'X-Composer-Report-Project';

    private const BEARER_PATTERN = '/^Bearer\s+(crt_[0-9a-f]{64})$/D';

    /**
     * @param array<string, mixed>|null $server $_SERVER, for the REDIRECT_HTTP_AUTHORIZATION fallback
     */
    public function authenticate(HTTPRequest $request, ?array $server = null): ?ComposerReportProject
    {
        $slug = (string) $request->getHeader(self::PROJECT_HEADER);
        $project = PayloadValidator::isValidSlug($slug)
            ? ComposerReportProject::get()->filter('Slug', $slug)->first()
            : null;
        $storedHash = $project instanceof ComposerReportProject && $project->Active ? $project->TokenHash : null;

        $token = $this->bearerToken($request, $server ?? $_SERVER);

        // Always hash and compare, so the timing does not tell which check failed
        $matches = TokenHasher::matches($token ?? Secret::fromString('invalid'), $token === null ? null : $storedHash);

        return $matches ? $project : null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function bearerToken(HTTPRequest $request, array $server): ?Secret
    {
        $header = (string) $request->getHeader('Authorization');

        if ($header === '' && is_string($server['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $header = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!preg_match(self::BEARER_PATTERN, trim($header), $matches)) {
            return null;
        }

        return Secret::fromString($matches[1]);
    }
}
