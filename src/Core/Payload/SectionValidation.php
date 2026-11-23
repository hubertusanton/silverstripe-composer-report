<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Payload;

/**
 * Outcome of PayloadValidator::validateSections(): null means the section is valid (or absent).
 */
final class SectionValidation
{
    public function __construct(
        public readonly ?string $outdatedError,
        public readonly ?string $auditError,
    ) {
    }

    public function isValid(): bool
    {
        return $this->outdatedError === null && $this->auditError === null;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return array_values(array_filter([$this->outdatedError, $this->auditError], 'is_string'));
    }

    /**
     * The payload with each invalid section set to null and its error at the
     * front of the errors list.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        if ($this->isValid()) {
            return $payload;
        }

        if ($this->outdatedError !== null) {
            $payload['outdated'] = null;
        }

        if ($this->auditError !== null) {
            $payload['audit'] = null;
        }

        $existing = is_array($payload['errors'] ?? null)
            ? array_values(array_filter($payload['errors'], 'is_string'))
            : [];
        $payload['errors'] = PayloadBuilder::limitErrors([...$this->errors(), ...$existing]);

        return $payload;
    }
}
