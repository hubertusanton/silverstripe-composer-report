<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

final class AdvisoryDiff
{
    /**
     * Advisories in $current whose key is not in $previousKeys, each key once.
     *
     * @param list<string>   $previousKeys
     * @param list<Advisory> $current
     * @return list<Advisory>
     */
    public function newAdvisories(array $previousKeys, array $current): array
    {
        $seen = array_fill_keys($previousKeys, true);
        $new = [];

        foreach ($current as $advisory) {
            $key = $advisory->key();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $new[] = $advisory;
        }

        return $new;
    }
}
