<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Client;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Webium\ComposerReport\Core\Client\OutputSink;

/**
 * Writes client output to a PolyOutput. Errors go to a separate stream
 * (STDERR by default): PolyOutput has no error channel and -q would hide them,
 * while cron should mail exactly those. Everything is written raw, so text like
 * "<project>" is not taken for a formatting tag.
 */
final class PolyOutputSink implements OutputSink
{
    /**
     * @param resource $errorStream
     */
    public function __construct(
        private readonly PolyOutput $output,
        private $errorStream,
    ) {
    }

    public function info(string $message): void
    {
        $this->output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    public function verbose(string $message): void
    {
        $this->output->writeln($message, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_VERBOSE);
    }

    public function error(string $message): void
    {
        fwrite($this->errorStream, $message . PHP_EOL);
    }

    /**
     * Also written under -q, so `--dry-run -q` gives pure JSON on stdout.
     */
    public function payload(string $json): void
    {
        $this->output->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }

    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }
}
