<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

enum UpdateLevel: string
{
    case Major = 'major';
    case Minor = 'minor';
    case Patch = 'patch';
    case Unknown = 'unknown';

    /**
     * Label for the step from $current to $latest: the first numeric segment
     * that differs decides (index 0 major, 1 minor, 2 and up patch).
     * Branch versions, "[none matched]" and equal versions give Unknown.
     */
    public static function between(string $current, string $latest): self
    {
        $from = self::segments($current);
        $to = self::segments($latest);

        if ($from === null || $to === null) {
            return self::Unknown;
        }

        $length = max(count($from), count($to));

        for ($index = 0; $index < $length; $index++) {
            if (($from[$index] ?? 0) !== ($to[$index] ?? 0)) {
                return match ($index) {
                    0 => self::Major,
                    1 => self::Minor,
                    default => self::Patch,
                };
            }
        }

        return self::Unknown;
    }

    /**
     * "v2.0.8" → [2, 0, 8]; "1.0.0-beta1", "1.0.0RC1" and "2.1-dev" lose their
     * suffix. Anything else (dev-main, 2.x-dev, [none matched]) gives null.
     *
     * @return list<int>|null
     */
    private static function segments(string $version): ?array
    {
        $version = trim($version);

        if ($version === '' || stripos($version, 'dev-') === 0) {
            return null;
        }

        $version = preg_replace('/^v/i', '', $version) ?? '';
        $version = preg_replace('/[-+@].*$/', '', $version) ?? '';

        if (!preg_match('/^(\d+(?:\.\d+)*)(?:[a-z]+\d*)?$/i', $version, $matches)) {
            return null;
        }

        return array_map('intval', explode('.', $matches[1]));
    }
}
