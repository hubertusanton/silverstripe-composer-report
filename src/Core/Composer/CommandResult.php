<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

/**
 * Outcome of one Composer command. $failure is set when the process did not
 * run to completion; the exit code is then null.
 */
final class CommandResult
{
    public const FAILURE_TIMEOUT = 'timeout';
    public const FAILURE_PROCESS_CONTROL_UNAVAILABLE = 'process_control_unavailable';
    public const FAILURE_NOT_STARTED = 'not_started';

    /**
     * @param list<string> $argv
     */
    public function __construct(
        public readonly array $argv,
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $duration,
        public readonly ?string $failure = null,
    ) {
    }

    /**
     * The command for messages: argv joined with spaces, without the environment.
     */
    public function commandLine(): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => preg_match('/^[\w.\/:=@%+-]+$/D', $arg)
                ? $arg
                : "'" . str_replace("'", "'\\''", $arg) . "'",
            $this->argv,
        ));
    }
}
