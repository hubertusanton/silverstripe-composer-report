<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

/**
 * Helpers for decoded JSON (json_decode with associative arrays), where an
 * empty object {} and an empty array [] both become [].
 */
final class JsonShape
{
    /**
     * The value as a map: an associative array or an empty array. A non-empty
     * list or a non-array gives null.
     *
     * @return array<array-key, mixed>|null
     */
    public static function asMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if ($value !== [] && array_is_list($value)) {
            return null;
        }

        return $value;
    }

    /**
     * The value as a list (including an empty array). A non-empty map or a
     * non-array gives null.
     *
     * @return list<mixed>|null
     */
    public static function asList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        return $value;
    }

    public static function isMap(mixed $value): bool
    {
        return self::asMap($value) !== null;
    }

    public static function isList(mixed $value): bool
    {
        return self::asList($value) !== null;
    }
}
