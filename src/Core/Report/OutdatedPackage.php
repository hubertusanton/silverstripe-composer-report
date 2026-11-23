<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

/**
 * One item from the "locked" (or "installed") list of `composer outdated --format=json`.
 */
final class OutdatedPackage
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $latest,
        public readonly ?string $latestStatus,
        public readonly bool $directDependency,
        public readonly bool $abandoned,
        public readonly ?string $replacement,
    ) {
    }

    /**
     * Null when the item has no string name and version.
     *
     * @param array<array-key, mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        $name = $item['name'] ?? null;
        $version = $item['version'] ?? null;

        if (!is_string($name) || !is_string($version)) {
            return null;
        }

        $abandoned = $item['abandoned'] ?? false;

        return new self(
            $name,
            $version,
            self::stringOrNull($item['latest'] ?? null),
            self::stringOrNull($item['latest-status'] ?? null),
            ($item['direct-dependency'] ?? false) === true,
            $abandoned === true || is_string($abandoned),
            is_string($abandoned) && $abandoned !== '' ? $abandoned : null,
        );
    }

    /**
     * Outdated when latest-status is anything but "up-to-date". Without a
     * latest-status the versions are compared.
     */
    public function isOutdated(): bool
    {
        if ($this->latestStatus !== null) {
            return $this->latestStatus !== 'up-to-date';
        }

        return $this->latest !== null && $this->latest !== $this->version;
    }

    public function updateLevel(): UpdateLevel
    {
        if ($this->latest === null) {
            return UpdateLevel::Unknown;
        }

        return UpdateLevel::between($this->version, $this->latest);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
