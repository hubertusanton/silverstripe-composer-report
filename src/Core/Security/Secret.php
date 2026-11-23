<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Security;

/**
 * Wraps a token so it does not show up in dumps, JSON, serialized data or
 * stack traces. There is deliberately no __toString(): the value is only
 * available through reveal().
 */
final class Secret implements \JsonSerializable
{
    private function __construct(
        #[\SensitiveParameter]
        private readonly string $value,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        return new self($value);
    }

    /**
     * Only for the Authorization header and TokenHasher.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A Secret cannot be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Secret cannot be unserialized');
    }
}
