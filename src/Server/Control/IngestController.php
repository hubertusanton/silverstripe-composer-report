<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Control;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Injector\Injector;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Server\Ingest\IngestAuthenticator;
use Webium\ComposerReport\Server\Ingest\IngestRateLimiter;
use Webium\ComposerReport\Server\Ingest\IngestService;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerActivation;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * POST /composer-report/ingest
 *
 * Cheap checks and authentication come before the body is parsed. Every
 * response is a short JSON document; no session, no CSRF, no caching.
 */
class IngestController extends Controller
{
    private const MAX_DETAILS = 10;

    private static array $allowed_actions = [
        'index',
    ];

    protected function init()
    {
        parent::init();

        if (!ServerActivation::isEnabled()) {
            $this->httpError(404);
        }

        HTTPCacheControlMiddleware::singleton()->disableCache(true);
    }

    public function index(HTTPRequest $request): HTTPResponse
    {
        $limiter = IngestRateLimiter::singleton();

        $rejection = $this->checkRequest($request);

        if ($rejection !== null) {
            return $this->rejectUnauthenticated($request, $limiter, $rejection);
        }

        $project = IngestAuthenticator::singleton()->authenticate($request);

        if ($project === null) {
            return $this->rejectUnauthenticated($request, $limiter, $this->error(401, 'unauthorized'));
        }

        return $this->handleAuthenticated($request, $project, $limiter);
    }

    /**
     * Method, content type and size. Null when the request may go on to authentication.
     */
    private function checkRequest(HTTPRequest $request): ?HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->error(405, 'method_not_allowed')->addHeader('Allow', 'POST');
        }

        $contentType = strtolower(trim(explode(';', (string) $request->getHeader('Content-Type'))[0]));

        if ($contentType !== 'application/json') {
            return $this->error(415, 'unsupported_media_type');
        }

        $length = trim((string) $request->getHeader('Content-Length'));
        $maxBytes = ServerSettings::singleton()->maxBodyBytes();

        if (!ctype_digit($length)) {
            return $this->error(411, 'length_required');
        }

        if ((int) $length > $maxBytes || strlen((string) $request->getBody()) > $maxBytes) {
            return $this->error(413, 'payload_too_large');
        }

        return null;
    }

    /**
     * Counts towards the per-IP limit; over the limit the answer becomes 429.
     */
    private function rejectUnauthenticated(
        HTTPRequest $request,
        IngestRateLimiter $limiter,
        HTTPResponse $response,
    ): HTTPResponse {
        $limited = $limiter->isAuthFailureLimited($request);
        $limiter->recordAuthFailure($request);

        if ($limited) {
            return $this->error(429, 'rate_limited')->addHeader('Retry-After', '3600');
        }

        return $response;
    }

    private function handleAuthenticated(
        HTTPRequest $request,
        ComposerReportProject $project,
        IngestRateLimiter $limiter,
    ): HTTPResponse {
        try {
            $payload = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(400, 'invalid_json');
        }

        if (!is_array($payload) || ($payload['project'] ?? null) !== $project->Slug) {
            return $this->error(400, 'project_mismatch');
        }

        $retryAfter = $limiter->projectRetryAfter($project);

        if ($retryAfter !== null) {
            return $this->error(429, 'rate_limited')->addHeader('Retry-After', (string) $retryAfter);
        }

        $validator = new PayloadValidator();
        $envelopeErrors = $validator->validateEnvelope($payload);

        if ($envelopeErrors !== []) {
            return $this->error(400, 'invalid_payload', array_slice($envelopeErrors, 0, self::MAX_DETAILS));
        }

        // Invalid sections are stored as null with a remark in errors
        $payload = $validator->validateSections($payload)->apply($payload);

        try {
            $report = IngestService::singleton()->ingest($project, $payload);
        } catch (\Throwable $exception) {
            // Neither the token nor the payload goes into the log
            Injector::inst()->get(LoggerInterface::class)->error(sprintf(
                'composer-report: storing a report for project %d failed: %s',
                $project->ID,
                get_class($exception),
            ));

            return $this->error(500, 'server_error');
        }

        return $this->json(202, ['status' => 'accepted', 'report' => $report->ID]);
    }

    /**
     * @param list<string> $details
     */
    private function error(int $status, string $code, array $details = []): HTTPResponse
    {
        $body = ['error' => $code];

        if ($details !== []) {
            $body['details'] = $details;
        }

        return $this->json($status, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): HTTPResponse
    {
        return HTTPResponse::create(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $status)
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('X-Content-Type-Options', 'nosniff');
    }
}
