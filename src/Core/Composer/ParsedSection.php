<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

/**
 * A parsed payload section: the decoded Composer JSON, or an error. Warnings
 * are extra remarks for the payload's errors list, also on success.
 */
final class ParsedSection
{
    /**
     * @param array<array-key, mixed>|null $data
     * @param list<string>                 $warnings
     */
    private function __construct(
        public readonly ?array $data,
        public readonly ?string $error,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $warnings
     */
    public static function ok(array $data, array $warnings = []): self
    {
        return new self($data, null, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public static function failed(string $error, array $warnings = []): self
    {
        return new self(null, $error, $warnings);
    }

    public function isAvailable(): bool
    {
        return $this->data !== null;
    }
}
