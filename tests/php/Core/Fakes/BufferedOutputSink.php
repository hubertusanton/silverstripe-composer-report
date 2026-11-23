<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Fakes;

use Webium\ComposerReport\Core\Client\OutputSink;

final class BufferedOutputSink implements OutputSink
{
    /**
     * @var list<string>
     */
    public array $info = [];

    /**
     * @var list<string>
     */
    public array $verbose = [];

    /**
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @var list<string>
     */
    public array $payloads = [];

    public function __construct(
        private readonly bool $isVerbose = false,
    ) {
    }

    public function info(string $message): void
    {
        $this->info[] = $message;
    }

    public function verbose(string $message): void
    {
        if ($this->isVerbose) {
            $this->verbose[] = $message;
        }
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function payload(string $json): void
    {
        $this->payloads[] = $json;
    }

    public function isVerbose(): bool
    {
        return $this->isVerbose;
    }

    /**
     * Everything that was written, for "never in the output" checks.
     */
    public function all(): string
    {
        return implode("\n", [...$this->info, ...$this->verbose, ...$this->errors, ...$this->payloads]);
    }
}
