<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessComposerRunner implements ComposerRunner
{
    /**
     * symfony/process needs all four; a disabled one throws an \Error that @ does not suppress.
     */
    private const PROCESS_FUNCTIONS = ['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate'];

    /**
     * The same flags on both commands, so their output is comparable and no plugin code runs.
     */
    private const COMMON_FLAGS = [
        '--locked',
        '--format=json',
        '--no-interaction',
        '--no-ansi',
        '--no-plugins',
        '--no-scripts',
    ];

    /**
     * @var array<string, string|false>|null
     */
    private ?array $env = null;

    /**
     * @var list<string>
     */
    private array $envWarnings = [];

    /**
     * @param list<string> $composerCommand ['composer'] or ['/opt/php83/bin/php', '/usr/local/bin/composer.phar']
     */
    public function __construct(
        private readonly array $composerCommand,
        private readonly string $workingDirectory,
        private readonly float $timeout,
        private readonly ProcessEnvironment $environment,
    ) {
    }

    public function unavailableReason(): ?string
    {
        $missing = array_values(array_filter(
            self::PROCESS_FUNCTIONS,
            static fn (string $function): bool => !function_exists($function),
        ));

        if ($missing === []) {
            return null;
        }

        return 'PHP process control functions are disabled (' . implode(', ', $missing) . '), Composer cannot run';
    }

    public function environmentWarnings(): array
    {
        $this->env();

        return $this->envWarnings;
    }

    public function version(): CommandResult
    {
        return $this->run([...$this->composerCommand, '--version', '--no-ansi']);
    }

    public function outdated(bool $directOnly): CommandResult
    {
        $argv = [...$this->composerCommand, 'outdated', ...self::COMMON_FLAGS];

        if ($directOnly) {
            $argv[] = '--direct';
        }

        return $this->run($argv);
    }

    public function audit(): CommandResult
    {
        return $this->run([...$this->composerCommand, 'audit', ...self::COMMON_FLAGS]);
    }

    /**
     * @param list<string> $argv
     */
    private function run(array $argv): CommandResult
    {
        $start = microtime(true);
        $process = null;

        try {
            $process = new Process($argv, $this->workingDirectory, $this->env(), null, $this->timeout);
            $process->run();

            return new CommandResult(
                $argv,
                $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput(),
                microtime(true) - $start,
            );
        } catch (ProcessTimedOutException) {
            return new CommandResult(
                $argv,
                null,
                $this->output($process, false),
                $this->output($process, true),
                microtime(true) - $start,
                CommandResult::FAILURE_TIMEOUT,
            );
        } catch (\Error $error) {
            return new CommandResult(
                $argv,
                null,
                '',
                $error->getMessage(),
                microtime(true) - $start,
                CommandResult::FAILURE_PROCESS_CONTROL_UNAVAILABLE,
            );
        } catch (\Throwable $exception) {
            return new CommandResult(
                $argv,
                null,
                '',
                $exception->getMessage(),
                microtime(true) - $start,
                CommandResult::FAILURE_NOT_STARTED,
            );
        }
    }

    /**
     * Computed once per runner, so warnings appear once and the fallback directory is checked once.
     *
     * @return array<string, string|false>
     */
    private function env(): array
    {
        if ($this->env === null) {
            $inherited = array_filter(getenv(), 'is_string');
            $warnings = [];
            $this->env = $this->environment->overrides($inherited, $warnings);
            $this->envWarnings = $warnings;
        }

        return $this->env;
    }

    private function output(?Process $process, bool $errorOutput): string
    {
        if ($process === null) {
            return '';
        }

        try {
            return $errorOutput ? $process->getErrorOutput() : $process->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }
}
