<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Composer;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Process\Process;
use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ProcessComposerRunner;
use Webium\ComposerReport\Core\Composer\ProcessEnvironment;

/**
 * Runs tests/bin/fake-composer.php as "Composer".
 */
class ProcessComposerRunnerTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const FLAGS = [
        '--locked',
        '--format=json',
        '--no-interaction',
        '--no-ansi',
        '--no-plugins',
        '--no-scripts',
    ];

    /**
     * @var array<string, string|false>
     */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['COMPOSER_REPORT_TOKEN', 'FAKE_COMPOSER_INHERITED'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        // Real OS variables: Process inherits what is both in getenv() and $_SERVER
        $this->setOsEnv('COMPOSER_REPORT_TOKEN', 'crt_' . str_repeat('ab', 32));
        $this->setOsEnv('FAKE_COMPOSER_INHERITED', 'yes');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            $this->setOsEnv($name, $value);
        }

        parent::tearDown();
    }

    public function testOutdatedArgumentsStdoutStderrAndEnvironment(): void
    {
        $result = $this->runner(['--fake-exit=1'])->outdated(true);
        $output = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($result->failure);
        $this->assertSame(1, $result->exitCode);
        $this->assertSame('fake composer stderr', $result->stderr);
        $this->assertGreaterThan(0.0, $result->duration);
        $this->assertSame(['outdated', ...self::FLAGS, '--direct'], $output['args']);
        $this->assertSame(realpath($this->workingDirectory()), realpath($output['cwd']));
        $this->assertSame('0', $output['env']['SHELL_VERBOSITY']);
        $this->assertSame('1', $output['env']['COMPOSER_NO_INTERACTION']);
        $this->assertSame('dev-main', $output['env']['COMPOSER_ROOT_VERSION']);
        $this->assertSame('yes', $output['env']['FAKE_COMPOSER_INHERITED'], 'control: OS variables are inherited');
        $this->assertFalse($output['env']['COMPOSER_REPORT_TOKEN']);
    }

    public function testAuditAndVersionArguments(): void
    {
        $runner = $this->runner();

        $audit = json_decode($runner->audit()->stdout, true, 512, JSON_THROW_ON_ERROR);
        $version = json_decode($runner->version()->stdout, true, 512, JSON_THROW_ON_ERROR);
        $outdated = json_decode($runner->outdated(false)->stdout, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['audit', ...self::FLAGS], $audit['args']);
        $this->assertSame(['--version', '--no-ansi'], $version['args']);
        $this->assertNotContains('--direct', $outdated['args']);
    }

    public function testTimeoutKillsTheProcess(): void
    {
        $result = $this->runner(['--fake-sleep=30'], 1.0)->audit();

        $this->assertSame(CommandResult::FAILURE_TIMEOUT, $result->failure);
        $this->assertNull($result->exitCode);
        $this->assertLessThan(10.0, $result->duration);

        $pid = (int) $result->stdout;
        $this->assertGreaterThan(0, $pid, 'the fake printed its pid before sleeping');
        $this->assertDirectoryDoesNotExist('/proc/' . $pid, 'the process is gone after the timeout');
    }

    public function testMissingBinary(): void
    {
        $runner = new ProcessComposerRunner(
            ['/nonexistent/composer-' . bin2hex(random_bytes(4))],
            $this->workingDirectory(),
            10.0,
            new ProcessEnvironment(),
        );

        $result = $runner->outdated(true);

        $this->assertTrue(
            $result->exitCode === 127 || $result->failure === CommandResult::FAILURE_NOT_STARTED,
            'exit code ' . var_export($result->exitCode, true) . ', failure ' . var_export($result->failure, true),
        );
    }

    public function testMissingWorkingDirectory(): void
    {
        $runner = new ProcessComposerRunner(
            $this->fakeCommand(),
            '/nonexistent-' . bin2hex(random_bytes(4)),
            10.0,
            new ProcessEnvironment(),
        );

        $this->assertSame(CommandResult::FAILURE_NOT_STARTED, $runner->audit()->failure);
    }

    public function testAvailableInThisProcess(): void
    {
        $this->assertNull($this->runner()->unavailableReason());
    }

    public function testEnvironmentWarningsAreReportedOnce(): void
    {
        $runner = $this->runner();

        $this->assertSame($runner->environmentWarnings(), $runner->environmentWarnings());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideProcessFunctions(): array
    {
        return [
            'proc_terminate'  => ['proc_terminate'],
            'proc_get_status' => ['proc_get_status'],
            'proc_close'      => ['proc_close'],
        ];
    }

    /**
     * @dataProvider provideProcessFunctions
     */
    #[DataProvider('provideProcessFunctions')]
    public function testUnavailableWhenAProcessFunctionIsDisabled(string $function): void
    {
        $autoload = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $code = sprintf(
            'require %s; $r = new %s([\'composer\'], \'/\', 1.0, new %s());'
            . ' echo $r->unavailableReason() ?? \'available\';',
            var_export($autoload, true),
            ProcessComposerRunner::class,
            ProcessEnvironment::class,
        );

        $child = new Process([PHP_BINARY, '-d', 'disable_functions=' . $function, '-r', $code]);
        $child->mustRun();

        $this->assertStringContainsString('disabled (' . $function . ')', $child->getOutput());
    }

    /**
     * @param list<string> $fakeOptions
     */
    private function runner(array $fakeOptions = [], float $timeout = 20.0): ProcessComposerRunner
    {
        return new ProcessComposerRunner(
            [...$this->fakeCommand(), ...$fakeOptions],
            $this->workingDirectory(),
            $timeout,
            new ProcessEnvironment(),
        );
    }

    /**
     * @return list<string>
     */
    private function fakeCommand(): array
    {
        return [PHP_BINARY, dirname(__DIR__, 3) . '/bin/fake-composer.php'];
    }

    private function workingDirectory(): string
    {
        return dirname(__DIR__, 3) . '/fixtures';
    }

    private function setOsEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            unset($_SERVER[$name]);
            return;
        }

        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }
}
