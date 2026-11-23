<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

interface OutputSink
{
    public function info(string $message): void;

    /**
     * Only shown with --verbose.
     */
    public function verbose(string $message): void;

    public function error(string $message): void;

    /**
     * The JSON payload of a dry run, written as-is.
     */
    public function payload(string $json): void;

    public function isVerbose(): bool;
}
