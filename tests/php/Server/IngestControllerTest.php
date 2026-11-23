<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\BasicAuth;
use SilverStripe\Security\BasicAuthMiddleware;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Server\Control\IngestController;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;
use Webium\ComposerReport\Tests\Core\Fixture;

class IngestControllerTest extends FunctionalTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    private const URL = 'composer-report/ingest';

    private ComposerReportProject $project;

    private Secret $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        [$this->project, $this->token] = $this->createProject();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    /**
     * A Config merge would land behind the catch-all route, so the route is added here.
     */
    protected function getExtraRoutes()
    {
        return [self::URL => IngestController::class] + parent::getExtraRoutes();
    }

    public function testAcceptedReport(): void
    {
        $response = $this->send($this->payload('valid-advisories.json'));

        $this->assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->assertSame('accepted', $body['status']);

        $report = ComposerReport::get()->byID($body['report']);
        $this->assertNotNull($report);
        $this->assertSame($this->project->ID, (int) $report->ProjectID);
        $this->assertSame(11, (int) $report->AdvisoryCount);
        $this->assertSame(2, (int) $report->OutdatedCount);
        $this->assertSame('8.3.36', $report->PhpVersion);

        $project = ComposerReportProject::get()->byID($this->project->ID);
        $this->assertSame(11, (int) $project->LastAdvisoryCount);
        $this->assertNotNull($project->LastReportAt);
        $this->assertNotNull($project->LastAuditAt);
    }

    public function testResponsesAreJsonWithoutCachingOrSession(): void
    {
        $response = $this->send($this->payload('valid-clean.json'));

        $this->assertStringStartsWith('application/json', (string) $response->getHeader('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));
        $this->assertNull($response->getHeader('Set-Cookie'));
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->get(self::URL);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeader('Allow'));
        $this->assertSame(['error' => 'method_not_allowed'], $this->json($response));
    }

    public function testUnsupportedMediaType(): void
    {
        $response = $this->send('x', ['Content-Type' => 'text/plain']);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $headers = ['Content-Type' => 'Application/JSON; charset=utf-8'];

        $response = $this->send($this->payload('valid-clean.json'), $headers);

        $this->assertSame(202, $response->getStatusCode());
    }

    public function testLengthRequired(): void
    {
        $response = $this->send('{}', ['Content-Length' => null]);

        $this->assertSame(411, $response->getStatusCode());
    }

    public function testPayloadTooLargeByHeaderAndByBody(): void
    {
        ServerSettings::config()->set('max_body_bytes', 100);

        $this->assertSame(413, $this->send('{}', ['Content-Length' => '101'])->getStatusCode());
        $this->assertSame(413, $this->send(str_repeat(' ', 150) . '{}', ['Content-Length' => '10'])->getStatusCode());
    }

    public function testUnauthorizedLooksTheSameForEveryCause(): void
    {
        [$inactive, $inactiveToken] = $this->createProject('inactive-project', false);
        $withoutHash = ComposerReportProject::create();
        $withoutHash->Title = 'No token';
        $withoutHash->Slug = 'no-token';
        $withoutHash->write();

        $cases = [
            'no headers'      => ['X-Composer-Report-Project' => null, 'Authorization' => null],
            'unknown project' => ['X-Composer-Report-Project' => 'unknown'],
            'inactive'        => [
                'X-Composer-Report-Project' => 'inactive-project',
                'Authorization'             => 'Bearer ' . $inactiveToken->reveal(),
            ],
            'no token hash'   => ['X-Composer-Report-Project' => 'no-token'],
            'wrong token'     => ['Authorization' => 'Bearer crt_' . str_repeat('0', 64)],
            'malformed token' => ['Authorization' => 'Bearer crt_fout'],
            'basic auth'      => ['Authorization' => 'Basic dXNlcjpwYXNz'],
        ];

        foreach ($cases as $case => $headers) {
            $response = $this->send($this->payload('valid-clean.json'), $headers);

            $this->assertSame(401, $response->getStatusCode(), $case);
            $this->assertSame('{"error":"unauthorized"}', (string) $response->getBody(), $case);
        }

        $this->assertSame(0, ComposerReport::get()->count());
        $this->assertTrue($inactive->exists());
    }

    public function testRedirectAuthorizationFallback(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token->reveal();

        try {
            $response = $this->send($this->payload('valid-clean.json'), ['Authorization' => null]);
        } finally {
            unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }

        $this->assertSame(202, $response->getStatusCode());
    }

    public function testAuthFailuresAreRateLimitedButAValidTokenStillWorks(): void
    {
        ServerSettings::config()->set('auth_failure_limit_per_hour', 3);
        $wrong = ['Authorization' => 'Bearer crt_' . str_repeat('0', 64)];

        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->send($this->payload('valid-clean.json'), $wrong)->getStatusCode();
        }

        $this->assertSame([401, 401, 401, 429, 429], $codes);
        // Over the limit, cheap rejections become 429 as well
        $this->assertSame(429, $this->send('x', ['Content-Type' => 'text/plain'])->getStatusCode());
        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());
    }

    public function testLimiterFailsOpen(): void
    {
        Injector::inst()->load([CacheInterface::class . '.RateLimiter' => ['class' => ThrowingCache::class]]);

        $wrongToken = ['Authorization' => 'Bearer crt_' . str_repeat('0', 64)];

        $response = $this->send($this->payload('valid-clean.json'), $wrongToken);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());
    }

    public function testInvalidJson(): void
    {
        $response = $this->send('{"schema_version": 1,');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'invalid_json'], $this->json($response));
    }

    public function testProjectMismatch(): void
    {
        $payload = json_decode($this->payload('valid-clean.json'), true);
        $payload['project'] = 'other-project';

        $response = $this->send((string) json_encode($payload));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'project_mismatch'], $this->json($response));
    }

    public function testInvalidPayloadHasDetails(): void
    {
        $payload = json_decode($this->payload('valid-clean.json'), true);
        $payload['schema_version'] = 2;
        unset($payload['environment']);

        $response = $this->send((string) json_encode($payload));
        $body = $this->json($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_payload', $body['error']);
        $this->assertCount(2, $body['details']);
    }

    public function testInvalidSectionIsStoredAsNull(): void
    {
        $response = $this->send($this->payload('valid-invalid-section.json'));

        $this->assertSame(202, $response->getStatusCode());

        $report = ComposerReport::get()->byID($this->json($response)['report']);
        $stored = $report->payloadData();

        $this->assertNull($stored['audit']);
        $this->assertNotNull($stored['outdated']);
        $this->assertStringStartsWith('audit section rejected:', $stored['errors'][0]);
    }

    public function testReportsPerProjectAreRateLimited(): void
    {
        ServerSettings::config()->set('rate_limit_per_hour', 2);
        DBDatetime::set_mock_now('2026-11-23 10:00:00');

        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());
        DBDatetime::set_mock_now('2026-11-23 10:20:00');
        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());

        $limited = $this->send($this->payload('valid-clean.json'));
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame(['error' => 'rate_limited'], $this->json($limited));
        $this->assertSame('2400', $limited->getHeader('Retry-After'));

        DBDatetime::set_mock_now('2026-11-23 11:00:01');
        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());
    }

    public function testNotFoundWhenTheServerIsOff(): void
    {
        $this->enableServer(false);

        $this->assertSame(404, $this->send($this->payload('valid-clean.json'))->getStatusCode());
    }

    public function testBasicAuthDoesNotApplyToTheIngest(): void
    {
        BasicAuth::config()->set('ignore_cli', false);
        BasicAuth::protect_entire_site(true);
        $middleware = Injector::inst()->get(BasicAuthMiddleware::class);

        // Control: without the exclusion the monitor asks for basic auth
        $middleware->setURLPatterns([]);
        $this->assertSame(401, $this->send($this->payload('valid-clean.json'))->getStatusCode());
        $this->assertSame(0, ComposerReport::get()->count());

        $middleware->setURLPatterns(self::basicAuthPatterns());
        $this->assertSame(202, $this->send($this->payload('valid-clean.json'))->getStatusCode());
    }

    private function payload(string $fixture): string
    {
        $payload = Fixture::json('payloads/' . $fixture);
        $payload['project'] = $this->project->Slug;

        return (string) json_encode($payload);
    }

    /**
     * POST with valid headers by default; a header set to null is left out.
     *
     * @param array<string, string|null> $headers
     */
    private function send(string $body, array $headers = []): HTTPResponse
    {
        $headers += [
            'Content-Type'              => 'application/json',
            'Content-Length'            => (string) strlen($body),
            'X-Composer-Report-Project' => $this->project->Slug,
            'Authorization'             => 'Bearer ' . $this->token->reveal(),
        ];

        return $this->post(self::URL, [], array_filter($headers, 'is_string'), null, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(HTTPResponse $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
