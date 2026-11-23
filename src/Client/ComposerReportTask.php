<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Client;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use Webium\ComposerReport\Core\Client\ExitCode;

/**
 * vendor/bin/sake dev/tasks/composer-report [--dry-run] [--verbose|-v]
 *
 * Runs composer outdated and audit and posts the result to the Composer
 * monitor. CLI only. CMS 5 sake always exits with 0, so a non-zero exit code
 * is passed on with exit().
 */
class ComposerReportTask extends BuildTask
{
    private static $segment = 'composer-report';

    protected $title = 'Composer report';

    protected $description = 'Runs composer outdated/audit and posts the result to the Composer monitor';

    /**
     * @var resource|null
     */
    private $errorStream = null;

    /**
     * Never in a browser, not even in dev mode.
     */
    public function canView(): bool
    {
        return Director::is_cli();
    }

    /**
     * Where error messages go; STDERR by default.
     *
     * @param resource $stream
     */
    public function setErrorStream($stream): static
    {
        $this->errorStream = $stream;

        return $this;
    }

    /**
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $exitCode = $this->runWithExitCode($request);

        if ($exitCode !== ExitCode::SUCCESS) {
            $this->terminate($exitCode);
        }
    }

    /**
     * Only --dry-run, --verbose and -v are recognised; other arguments are ignored.
     */
    public function runWithExitCode(HTTPRequest $request): int
    {
        if (!Director::is_cli()) {
            return ExitCode::CONFIG_ERROR;
        }

        $args = array_map('strval', (array) $request->getVar('args'));
        $verbose = in_array('--verbose', $args, true) || in_array('-v', $args, true);

        $settings = ClientSettings::singleton();
        $config = $settings->createConfig();
        $sink = new EchoOutputSink($verbose, $this->errorStream ?? $this->defaultErrorStream());

        return $settings->createClient($config)->run(
            $config,
            in_array('--dry-run', $args, true),
            $sink,
            new \DateTimeImmutable(),
        );
    }

    /**
     * Ends the process with the exit code; overridden in tests.
     */
    protected function terminate(int $exitCode): void
    {
        exit($exitCode);
    }

    /**
     * @return resource
     */
    private function defaultErrorStream()
    {
        if (defined('STDERR')) {
            return STDERR;
        }

        $stream = fopen('php://stderr', 'w');

        if ($stream === false) {
            throw new \RuntimeException('Cannot open php://stderr');
        }

        return $stream;
    }
}
