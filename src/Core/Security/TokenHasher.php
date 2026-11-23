<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Security;

/**
 * Project tokens: "crt_" plus 64 hex characters, stored as a sha256 hash.
 * The prefix keeps .env parsers from reading the token as a number and makes
 * leaked tokens recognisable for TextSanitizer.
 */
final class TokenHasher
{
    public const PREFIX = 'crt_';

    /**
     * @return array{0: Secret, 1: string} the plaintext token and its hash
     */
    public static function generate(): array
    {
        $token = Secret::fromString(self::PREFIX . bin2hex(random_bytes(32)));

        return [$token, self::hash($token)];
    }

    public static function hash(#[\SensitiveParameter] Secret $token): string
    {
        return hash('sha256', $token->reveal());
    }

    /**
     * Constant-time comparison. Without a stored hash it still compares
     * against a dummy, so the timing does not reveal whether a hash exists.
     */
    public static function matches(#[\SensitiveParameter] Secret $token, ?string $storedHash): bool
    {
        $hash = self::hash($token);

        if ($storedHash === null || $storedHash === '') {
            hash_equals(str_repeat('0', 64), $hash);

            return false;
        }

        return hash_equals($storedHash, $hash);
    }
}
