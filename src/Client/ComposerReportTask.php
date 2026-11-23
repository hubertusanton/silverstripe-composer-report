<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Client;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Webium\ComposerReport\Core\Client\ExitCode;

/**
 * vendor/bin/sake tasks:composer-report [--dry-run] [-v] [-q]
 *
 * Runs composer outdated and audit and posts the result to the Composer
 * monitor. CLI only. --verbose is Symfony's global option and is not declared here.
 */
class ComposerReportTask extends BuildTask
{
    protected static string $commandName = 'composer-report';

    protected string $title = 'Composer report';

    protected static string $description = 'Runs composer outdated/audit and posts the result to the Composer monitor';

    /**
     * @var resource|null
     */
    private $errorStream = null;

    /**
     * Never in a browser, not even in dev mode.
     */
    public static function canRunInBrowser(): bool
    {
        return false;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Print the payload instead of sending it'),
        ];
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

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (!Director::is_cli()) {
            return ExitCode::CONFIG_ERROR;
        }

        $settings = ClientSettings::singleton();
        $config = $settings->createConfig();
        $sink = new PolyOutputSink($output, $this->errorStream ?? $this->defaultErrorStream());

        return $settings->createClient($config)->run(
            $config,
            (bool) $input->getOption('dry-run'),
            $sink,
            new \DateTimeImmutable(),
        );
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
