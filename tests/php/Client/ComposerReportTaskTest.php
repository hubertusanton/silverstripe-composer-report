<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Client;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Client\ComposerReportTask;
use Webium\ComposerReport\Core\Client\ExitCode;
use Webium\ComposerReport\Core\Client\ReportSender;
use Webium\ComposerReport\Core\Client\SendResult;
use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ComposerRunner;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Tests\Core\Fakes\FakeComposerRunner;
use Webium\ComposerReport\Tests\Core\Fakes\FakeReportSender;

class ComposerReportTaskTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TOKEN = 'crt_1111111111111111111111111111111111111111111111111111111111111111';

    /**
     * @var array<string, mixed>
     */
    private array $savedEnv = [];

    private FakeReportSender $sender;

    /**
     * @var resource
     */
    private $stderr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedEnv = Environment::getVariables();
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', 'https://monitor.example.com/composer-report/ingest');
        Environment::setEnv('COMPOSER_REPORT_PROJECT', 'example-project');
        Environment::setEnv('COMPOSER_REPORT_TOKEN', self::TOKEN);
        Environment::setEnv('COMPOSER_REPORT_COMPOSER_BIN', '');

        Injector::inst()->load([ComposerRunner::class => ['factory' => FakeRunnerFactory::class]]);
        FakeRunnerFactory::$runner = FakeComposerRunner::fromFixtures('vulnerable');
        $this->useSender(new FakeReportSender());

        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);
        $this->stderr = $stream;
    }

    protected function tearDown(): void
    {
        Environment::setVariables($this->savedEnv);
        FakeRunnerFactory::$runner = null;
        fclose($this->stderr);

        parent::tearDown();
    }

    public function testSendsTheReport(): void
    {
        [$exitCode, $stdout] = $this->runTask();

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('example-project', $this->sender->sent[0]['project']);
        $this->assertSame(self::TOKEN, $this->sender->sent[0]['token']);
        $this->assertStringContainsString('Report sent to monitor (HTTP 202).', $stdout);
        $this->assertSame('', $this->stderr());
    }

    public function testDryRunPrintsOnlyJson(): void
    {
        [$exitCode, $stdout] = $this->runTask(['--dry-run']);

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertSame([], $this->sender->sent);

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
    }

    public function testDryRunNeedsNoEndpointOrToken(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', '');
        Environment::setEnv('COMPOSER_REPORT_TOKEN', '');

        $this->assertSame(ExitCode::SUCCESS, $this->runTask(['--dry-run'])[0]);
    }

    public function testUnknownArgumentsAreIgnored(): void
    {
        [$exitCode, $stdout] = $this->runTask(['--dry-run', '--no-database', '--whatever']);

        $this->assertSame(ExitCode::SUCCESS, $exitCode);
        $this->assertJson($stdout);
    }

    public function testConfigError(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', '');

        $this->assertSame(ExitCode::CONFIG_ERROR, $this->runTask()[0]);
        $this->assertSame("COMPOSER_REPORT_ENDPOINT is not set.\n", $this->stderr());
        $this->assertSame([], $this->sender->sent);
    }

    public function testComposerError(): void
    {
        FakeRunnerFactory::$runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', ['composer', 'outdated']),
            new CommandResult(['composer', 'audit'], 100, '', 'Network disabled', 1.0),
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runTask()[0]);
        $this->assertCount(1, $this->sender->sent);
        $this->assertStringContainsString('`composer audit` gave no valid JSON (exit 100)', $this->stderr());
    }

    public function testHttpError(): void
    {
        $notFound = SendResult::failed(404, 'Monitor rejected the report (HTTP 404): not found');
        $this->useSender(new FakeReportSender($notFound));

        $this->assertSame(ExitCode::HTTP_ERROR, $this->runTask()[0]);
        $this->assertStringContainsString('HTTP 404', $this->stderr());
    }

    public function testRunPassesNonZeroExitCodesToTerminate(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', '');
        $task = TestComposerReportTask::create()->setErrorStream($this->stderr);

        $this->capture(fn () => $task->run($this->request()));
        $this->assertSame(ExitCode::CONFIG_ERROR, $task->terminatedWith);

        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', 'https://monitor.example.com/composer-report/ingest');
        $success = TestComposerReportTask::create()->setErrorStream($this->stderr);

        $this->capture(fn () => $success->run($this->request()));
        $this->assertNull($success->terminatedWith, 'exit code 0 does not call exit()');
    }

    public function testVerboseShowsCommandsButNeverTheToken(): void
    {
        foreach (['--verbose', '-v'] as $flag) {
            [, $stdout] = $this->runTask([$flag]);

            $this->assertStringContainsString('$ composer audit --locked --format=json', $stdout, $flag);
            $this->assertStringNotContainsString(self::TOKEN, $stdout . $this->stderr(), $flag);
        }
    }

    public function testOnlyRunsOnTheCommandLine(): void
    {
        $this->assertTrue(ComposerReportTask::singleton()->canView());
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function runTask(array $args = []): array
    {
        $task = ComposerReportTask::create()->setErrorStream($this->stderr);
        $exitCode = 0;

        $stdout = $this->capture(function () use ($task, $args, &$exitCode): void {
            $exitCode = $task->runWithExitCode($this->request($args));
        });

        return [$exitCode, $stdout];
    }

    /**
     * @param list<string> $args
     */
    private function request(array $args = []): HTTPRequest
    {
        return new HTTPRequest('GET', 'dev/tasks/composer-report', $args === [] ? [] : ['args' => $args]);
    }

    private function capture(callable $callback): string
    {
        ob_start();

        try {
            $callback();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function useSender(FakeReportSender $sender): void
    {
        $this->sender = $sender;
        Injector::inst()->registerService($sender, ReportSender::class);
    }

    private function stderr(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }
}
