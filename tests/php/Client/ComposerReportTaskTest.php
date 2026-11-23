<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Client;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
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

    private BufferedOutput $stdout;

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

        $this->stdout = new BufferedOutput();
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
        $this->assertSame(ExitCode::SUCCESS, $this->runTask());
        $this->assertCount(1, $this->sender->sent);
        $this->assertSame('example-project', $this->sender->sent[0]['project']);
        $this->assertSame(self::TOKEN, $this->sender->sent[0]['token']);
        $this->assertStringContainsString('Report sent to monitor (HTTP 202).', $this->stdout->fetch());
        $this->assertSame('', $this->stderr());
    }

    public function testDryRunPrintsValidJson(): void
    {
        $this->assertSame(ExitCode::SUCCESS, $this->runTask(['--dry-run' => true]));
        $this->assertSame([], $this->sender->sent);

        $stdout = $this->stdout->fetch();
        $json = substr($stdout, (int) strpos($stdout, '{'), strrpos($stdout, '}') - strpos($stdout, '{') + 1);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], (new PayloadValidator())->validateEnvelope($payload));
    }

    public function testDryRunUnderQuietGivesOnlyJson(): void
    {
        $this->runTask(['--dry-run' => true], OutputInterface::VERBOSITY_QUIET);

        $payload = json_decode($this->stdout->fetch(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('example-project', $payload['project']);
    }

    public function testDryRunNeedsNoEndpointOrToken(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', '');
        Environment::setEnv('COMPOSER_REPORT_TOKEN', '');

        $this->assertSame(ExitCode::SUCCESS, $this->runTask(['--dry-run' => true], OutputInterface::VERBOSITY_QUIET));
    }

    public function testConfigError(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', '');

        $this->assertSame(ExitCode::CONFIG_ERROR, $this->runTask());
        $this->assertSame("COMPOSER_REPORT_ENDPOINT is not set.\n", $this->stderr());
        $this->assertSame([], $this->sender->sent);
    }

    public function testComposerError(): void
    {
        FakeRunnerFactory::$runner = new FakeComposerRunner(
            FakeComposerRunner::fixtureResult('clean', 'outdated-direct', ['composer', 'outdated']),
            new CommandResult(['composer', 'audit'], 100, '', 'Network disabled', 1.0),
        );

        $this->assertSame(ExitCode::COMPOSER_ERROR, $this->runTask());
        $this->assertCount(1, $this->sender->sent);
        $this->assertStringContainsString('`composer audit` gave no valid JSON (exit 100)', $this->stderr());
    }

    public function testHttpError(): void
    {
        $notFound = SendResult::failed(404, 'Monitor rejected the report (HTTP 404): not found');
        $this->useSender(new FakeReportSender($notFound));

        $this->assertSame(ExitCode::HTTP_ERROR, $this->runTask());
        $this->assertStringContainsString('HTTP 404', $this->stderr());
    }

    public function testErrorsAreWrittenUnderQuiet(): void
    {
        Environment::setEnv('COMPOSER_REPORT_PROJECT', '');

        $this->runTask([], OutputInterface::VERBOSITY_QUIET);

        $this->assertSame('', $this->stdout->fetch());
        $this->assertSame("COMPOSER_REPORT_PROJECT is not set.\n", $this->stderr());
    }

    public function testVerboseShowsCommandsButNeverTheToken(): void
    {
        $this->runTask([], OutputInterface::VERBOSITY_VERBOSE);

        $stdout = $this->stdout->fetch();

        $this->assertStringContainsString('$ composer audit --locked --format=json', $stdout);
        $this->assertStringNotContainsString(self::TOKEN, $stdout . $this->stderr());
    }

    public function testCannotRunInTheBrowser(): void
    {
        $this->assertFalse(ComposerReportTask::canRunInBrowser());
        $this->assertTrue(ComposerReportTask::canRunInCli());
        $this->assertSame('tasks:composer-report', ComposerReportTask::getName());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runTask(array $parameters = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): int
    {
        $task = ComposerReportTask::create()->setErrorStream($this->stderr);
        $input = new ArrayInput($parameters, new InputDefinition($task->getOptions()));
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, $verbosity, false, $this->stdout);

        return $task->run($input, $output);
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
