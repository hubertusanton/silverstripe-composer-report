<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Client;

use DateTimeImmutable;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Client\ClientConfig;
use Webium\ComposerReport\Core\Client\ExitCode;
use Webium\ComposerReport\Core\Client\ReportClient;
use Webium\ComposerReport\Core\Client\SendResult;
use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ComposerOutputParser;
use Webium\ComposerReport\Core\Payload\PayloadBuilder;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Core\Security\TextSanitizer;
use Webium\ComposerReport\Tests\Core\Fakes\BufferedOutputSink;
use Webium\ComposerReport\Tests\Core\Fakes\FakeComposerRunner;
use Webium\ComposerReport\Tests\Core\Fakes\FakeReportSender;
use Webium\ComposerReport\Tests\Core\Fixture;

class ReportClientTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const OUTDATED_ARGV = ['composer', 'outdated'];
    private const AUDIT_ARGV = ['composer', 'audit', '--locked', '--format=json'];
    private const UNAVAILABLE = 'PHP process control functions are disabled (proc_get_status), Composer cannot run';

    private FakeReportSender $sender;

    private BufferedOutputSink $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new FakeReportSender();
        $this->out = new BufferedOutputSink();
    }

    public function testSuccessfulReport(): void
    {
        $runner = FakeComposerRunner::fromFixtures('vulnerable');

        $this->assertSame(ExitCode::SUCCESS, $this->runClient($runner));
        $this->assertSame(['unavailableReason', 'version', 'outdated --direct', 'audit'], $runner->calls);
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('https://monitor.example.com/composer-report/ingest', $this->sender->sent[0]['endpoint']);
        $this->assertSame('example-project', $this->sender->sent[0]['project']);
        $this->assertSame(15.0, $this->sender->sent[0]['timeout']);
        $this->assertSame(['Report sent to monitor (HTTP 202).'], $this->out->info);
        $this->assertSame([], $this->out->errors);

        $payload = $this->sentPayload();
        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
        $this->assertSame('2.11.2', $payload['environment']['composer_version']);
        $this->assertSame('2026-11-21T03:00:00+01:00', $payload['generated_at']);
        $this->assertSame(Fixture::json('composer/vulnerable.audit.json'), $payload['audit']);
        $this->assertStringNotContainsString("\n", $this->sender->sent[0]['json'], 'sent JSON is compact');
    }

    public function testAllOutdatedPackagesWhenNotDirectOnly(): void
    {
        $runner = FakeComposerRunner::fromFixtures('vulnerable', false);

        $this->runClient($runner, ClientConfigTest::config(directOnly: false));

        $this->assertContains('outdated', $runner->calls);
    }

    public function testConfigErrorStopsBeforeComposer(): void
    {
        $runner = FakeComposerRunner::fromFixtures('clean');

        $exitCode = $this->runClient($runner, ClientConfigTest::config(endpoint: null));

        $this->assertSame(ExitCode::CONFIG_ERROR, $exitCode);
        $this->assertSame([], $runner->calls);
        $this->assertSame([], $this->sender->sent);
        $this->assertSame(['COMPOSER_REPORT_ENDPOINT is not set.'], $this->out->errors);
    }

    public function testComposerFailureIsStillSent(): void
    {
        $runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('vulnerable', 'outdated-direct', self::OUTDATED_ARGV),
            new CommandResult(self::AUDIT_ARGV, 100, '', 'Network disabled, request canceled', 1.0),
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runClient($runner));
        $this->assertCount(1, $this->sender->sent);

        $payload = $this->sentPayload();
        $this->assertNull($payload['audit']);
        $this->assertNotNull($payload['outdated']);
        $this->assertCount(2, $payload['errors']);
        $this->assertStringContainsString('(exit 100): Network disabled', $payload['errors'][0]);
        $this->assertSame(ComposerOutputParser::CACHE_WARNING, $payload['errors'][1]);
        $this->assertSame($payload['errors'], $this->out->errors);
        $this->assertSame(['Report sent to monitor (HTTP 202).'], $this->out->info);
    }

    public function testUnavailableProcessControlIsSentWithBothSectionsNull(): void
    {
        $runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', self::OUTDATED_ARGV),
            FakeComposerRunner::fixtureResult('clean', 'audit', self::AUDIT_ARGV),
            self::UNAVAILABLE,
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runClient($runner));
        $this->assertSame(['unavailableReason'], $runner->calls);

        $payload = $this->sentPayload();
        $this->assertNull($payload['outdated']);
        $this->assertNull($payload['audit']);
        $this->assertSame([self::UNAVAILABLE], $payload['errors']);
        $this->assertNull($payload['environment']['composer_version']);
    }

    public function testInvalidSectionIsSentAsNull(): void
    {
        $audit = '{"advisories": {"a/b": [{"packageName": "a/b", "title": "no affectedVersions"}]}}';
        $runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', self::OUTDATED_ARGV),
            new CommandResult(self::AUDIT_ARGV, 1, $audit, '', 1.0),
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runClient($runner));

        $payload = $this->sentPayload();
        $this->assertNull($payload['audit']);
        $this->assertSame(
            ['audit section rejected: advisory a/b[0] needs a string affectedVersions'],
            $payload['errors'],
        );
    }

    public function testHttpFailureWinsOverComposerFailure(): void
    {
        $runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', self::OUTDATED_ARGV),
            new CommandResult(self::AUDIT_ARGV, 100, '', 'network', 1.0),
        );
        $this->sender = new FakeReportSender(SendResult::failed(401, 'Monitor rejected the token (HTTP 401).'));

        $this->assertSame(ExitCode::HTTP_ERROR, $this->runClient($runner));
        $this->assertContains('Monitor rejected the token (HTTP 401).', $this->out->errors);
        $this->assertSame([], $this->out->info);
    }

    public function testHttpFailureAlone(): void
    {
        $this->sender = new FakeReportSender(SendResult::failed(null, 'Could not reach the monitor: timeout'));

        $this->assertSame(ExitCode::HTTP_ERROR, $this->runClient(FakeComposerRunner::fromFixtures('clean')));
    }

    public function testDryRunPrintsThePayloadAndSendsNothing(): void
    {
        $config = ClientConfigTest::config(endpoint: null, token: null);

        $exitCode = $this->runClient(FakeComposerRunner::fromFixtures('vulnerable'), $config, true);

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertSame([], $this->sender->sent);
        $this->assertCount(1, $this->out->payloads);
        $this->assertStringContainsString("\n", $this->out->payloads[0], 'dry-run JSON is pretty printed');
        $this->assertSame([], (new PayloadValidator())->validateEnvelope(json_decode($this->out->payloads[0], true)));
        $this->assertSame([], $this->out->info);
    }

    public function testDryRunWithComposerFailure(): void
    {
        $runner = new FakeComposerRunner(
            new CommandResult(self::OUTDATED_ARGV, 127, '', 'composer: not found', 0.1),
            new CommandResult(self::AUDIT_ARGV, 127, '', 'composer: not found', 0.1),
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runClient($runner, null, true));
        $this->assertCount(1, $this->out->payloads);
        $this->assertStringContainsString('COMPOSER_REPORT_COMPOSER_BIN', $this->out->errors[0]);
    }

    public function testEmptyLockIsClean(): void
    {
        $this->assertSame(ExitCode::SUCCESS, $this->runClient(FakeComposerRunner::fromFixtures('empty')));

        $payload = $this->sentPayload();
        $this->assertSame([], $payload['outdated']);
        $this->assertSame(['advisories' => []], $payload['audit']);
        $this->assertSame([], $payload['errors']);
    }

    public function testEnvironmentWarningsGoIntoErrors(): void
    {
        $warning = 'COMPOSER_HOME not set: the fallback directory is a symlink';
        $runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', self::OUTDATED_ARGV),
            FakeComposerRunner::fixtureResult('clean', 'audit', self::AUDIT_ARGV),
            null,
            [$warning],
        );

        $exitCode = $this->runClient($runner);

        $this->assertSame([$warning], $this->sentPayload()['errors']);
        $this->assertSame(ExitCode::SUCCESS, $exitCode, 'a warning alone is not a Composer failure');
    }

    public function testVerboseShowsTheCommands(): void
    {
        $this->out = new BufferedOutputSink(true);

        $this->runClient(FakeComposerRunner::fromFixtures('empty'));

        $verbose = implode("\n", $this->out->verbose);
        $this->assertStringContainsString('$ composer audit --locked --format=json (exit 0, 0.5 s)', $verbose);
        $this->assertContains('stderr: No packages - skipping audit.', $this->out->verbose);
    }

    public function testTokenNeverInTheOutput(): void
    {
        // A token without the crt_ prefix, so only the exact-match masking can catch it
        $token = 'plain-secret-token-value';
        $runner = new FakeComposerRunner(
            new CommandResult(self::OUTDATED_ARGV, 1, $token, 'Authorization: Bearer ' . $token, 1.0),
            new CommandResult(self::AUDIT_ARGV, 1, 'not json ' . $token, 'stderr mentions ' . $token, 1.0),
            null,
            ['warning with ' . $token],
        );
        $rejected = SendResult::failed(500, 'Monitor rejected the report (HTTP 500): ' . $token);
        $this->sender = new FakeReportSender($rejected);
        $this->out = new BufferedOutputSink(true);

        $this->runClient($runner, ClientConfigTest::config(token: $token));

        $this->assertNotSame('', $this->out->all());
        $this->assertStringNotContainsString($token, $this->out->all());
        $this->assertStringNotContainsString($token, $this->sender->sent[0]['json']);
    }

    private function runClient(FakeComposerRunner $runner, ?ClientConfig $config = null, bool $dryRun = false): int
    {
        $sanitizer = new TextSanitizer('/var/www/site');
        $client = new ReportClient(
            $runner,
            $this->sender,
            new PayloadBuilder(),
            new PayloadValidator(),
            new ComposerOutputParser($sanitizer),
            $sanitizer,
        );

        return $client->run(
            $config ?? ClientConfigTest::config(),
            $dryRun,
            $this->out,
            new DateTimeImmutable('2026-11-21T03:00:00+01:00'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(): array
    {
        return json_decode($this->sender->sent[0]['json'], true, 512, JSON_THROW_ON_ERROR);
    }
}
