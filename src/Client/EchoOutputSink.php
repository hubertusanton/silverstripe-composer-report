<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Client;

use Webium\ComposerReport\Core\Client\OutputSink;

/**
 * Writes client output for the CMS 5 task: messages are echoed (sake prints
 * them on stdout), errors go to a separate stream (STDERR by default), so
 * cron with "> /dev/null" only mails on errors.
 */
final class EchoOutputSink implements OutputSink
{
    /**
     * @param resource $errorStream
     */
    public function __construct(
        private readonly bool $verbose,
        private $errorStream,
    ) {
    }

    public function info(string $message): void
    {
        echo $message, PHP_EOL;
    }

    public function verbose(string $message): void
    {
        if ($this->verbose) {
            echo $message, PHP_EOL;
        }
    }

    public function error(string $message): void
    {
        fwrite($this->errorStream, $message . PHP_EOL);
    }

    public function payload(string $json): void
    {
        echo $json, PHP_EOL;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }
}
