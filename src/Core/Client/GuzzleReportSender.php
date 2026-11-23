<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Webium\ComposerReport\Core\Security\Secret;

final class GuzzleReportSender implements ReportSender
{
    private const BODY_EXCERPT_LENGTH = 200;

    public function __construct(
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function send(
        string $endpoint,
        string $project,
        #[\SensitiveParameter] Secret $token,
        string $json,
        float $timeout,
    ): SendResult {
        try {
            $response = ($this->client ?? new Client())->request('POST', $endpoint, [
                'timeout'         => $timeout,
                'connect_timeout' => min(5.0, $timeout),
                // A redirect would turn the POST into a GET or resend the token elsewhere
                'allow_redirects' => false,
                'http_errors'     => false,
                // Deliberately not configurable
                'verify'          => true,
                'headers'         => [
                    'Authorization'             => 'Bearer ' . $token->reveal(),
                    'X-Composer-Report-Project' => $project,
                    'Content-Type'              => 'application/json',
                    'Accept'                    => 'application/json',
                    'User-Agent'                => self::userAgent(),
                ],
                'body'            => $json,
            ]);
        } catch (GuzzleException | \InvalidArgumentException $exception) {
            return SendResult::failed(null, sprintf(
                'Could not reach the monitor: %s (under cron, proxy variables such as HTTPS_PROXY may be missing)',
                $exception->getMessage(),
            ));
        }

        return $this->result($response);
    }

    private function result(ResponseInterface $response): SendResult
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return SendResult::delivered($status, sprintf('Report sent to monitor (HTTP %d).', $status));
        }

        if ($status >= 300 && $status < 400) {
            $location = $response->getHeaderLine('Location');

            return SendResult::failed($status, sprintf(
                'Monitor answered with a redirect (HTTP %d%s); check the endpoint (https, www, trailing slash).',
                $status,
                $location === '' ? '' : ' to ' . $location,
            ));
        }

        if ($status === 401) {
            if (stripos($response->getHeaderLine('WWW-Authenticate'), 'basic') === 0) {
                return SendResult::failed(
                    $status,
                    'Monitor asks for basic auth (HTTP 401); exclude the ingest endpoint from basic auth.',
                );
            }

            return SendResult::failed($status, 'Monitor rejected the token (HTTP 401).');
        }

        return SendResult::failed(
            $status,
            sprintf('Monitor rejected the report (HTTP %d): %s', $status, $this->describeBody($response)),
        );
    }

    /**
     * The "error" of a JSON body, otherwise the start of the body as plain text.
     */
    private function describeBody(ResponseInterface $response): string
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
            return $decoded['error'];
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return $text === '' ? 'empty response' : mb_substr(mb_scrub($text, 'UTF-8'), 0, self::BODY_EXCERPT_LENGTH);
    }

    private static function userAgent(): string
    {
        $version = InstalledVersions::isInstalled('webium/silverstripe-composer-report')
            ? InstalledVersions::getPrettyVersion('webium/silverstripe-composer-report')
            : null;

        return 'webium-composer-report/' . ($version ?? 'dev');
    }
}
