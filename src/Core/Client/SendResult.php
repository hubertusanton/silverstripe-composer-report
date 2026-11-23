<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

final class SendResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly ?int $statusCode,
        public readonly string $message,
    ) {
    }

    public static function delivered(int $statusCode, string $message): self
    {
        return new self(true, $statusCode, $message);
    }

    /**
     * @param int|null $statusCode null when no response was received
     */
    public static function failed(?int $statusCode, string $message): self
    {
        return new self(false, $statusCode, $message);
    }
}
