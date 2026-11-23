<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core;

use RuntimeException;

/**
 * Reads files from tests/fixtures.
 */
final class Fixture
{
    public static function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $relative;
    }

    public static function read(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));

        if ($contents === false) {
            throw new RuntimeException('Fixture not readable: ' . $relative);
        }

        return $contents;
    }

    public static function json(string $relative): mixed
    {
        return json_decode(self::read($relative), true, 512, JSON_THROW_ON_ERROR);
    }
}
