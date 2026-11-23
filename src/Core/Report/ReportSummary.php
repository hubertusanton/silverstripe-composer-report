<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

/**
 * Counts and rows from a (validated) payload. Tolerant: unexpected shapes
 * inside a section give empty results instead of exceptions.
 */
final class ReportSummary
{
    /**
     * @param list<OutdatedPackage> $outdatedPackages
     * @param list<Advisory>        $advisories
     * @param list<string>|null     $auditAbandoned package names from audit.abandoned, null when absent
     * @param list<string>          $errors
     */
    private function __construct(
        private readonly bool $outdatedAvailable,
        private readonly bool $auditAvailable,
        private readonly array $outdatedPackages,
        private readonly array $advisories,
        private readonly ?array $auditAbandoned,
        private readonly array $errors,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $outdated = $payload['outdated'] ?? null;
        $audit = $payload['audit'] ?? null;

        $auditMap = JsonShape::asMap($audit);
        $auditAbandoned = null;

        if ($auditMap !== null && array_key_exists('abandoned', $auditMap)) {
            $abandoned = JsonShape::asMap($auditMap['abandoned']);
            $auditAbandoned = $abandoned === null ? null : array_map('strval', array_keys($abandoned));
        }

        $errors = [];

        foreach (JsonShape::asList($payload['errors'] ?? null) ?? [] as $error) {
            if (is_string($error)) {
                $errors[] = $error;
            }
        }

        return new self(
            is_array($outdated),
            is_array($audit),
            self::parseOutdated($outdated),
            self::parseAdvisories($auditMap),
            $auditAbandoned,
            $errors,
        );
    }

    public function isOutdatedAvailable(): bool
    {
        return $this->outdatedAvailable;
    }

    public function isAuditAvailable(): bool
    {
        return $this->auditAvailable;
    }

    /**
     * Packages whose latest-status is not "up-to-date".
     */
    public function outdatedCount(): int
    {
        return count(array_filter(
            $this->outdatedPackages,
            static fn (OutdatedPackage $package): bool => $package->isOutdated(),
        ));
    }

    /**
     * Number of advisories (not packages). Ignored advisories are not counted.
     */
    public function advisoryCount(): int
    {
        return count($this->advisories);
    }

    public function advisoryPackageCount(): int
    {
        return count(array_unique(array_map(
            static fn (Advisory $advisory): string => $advisory->packageName,
            $this->advisories,
        )));
    }

    /**
     * Keys of audit.abandoned (Composer 2.7+), otherwise the abandoned outdated items.
     */
    public function abandonedCount(): int
    {
        if ($this->auditAbandoned !== null) {
            return count($this->auditAbandoned);
        }

        return count(array_filter(
            $this->outdatedPackages,
            static fn (OutdatedPackage $package): bool => $package->abandoned,
        ));
    }

    /**
     * @return list<OutdatedPackage>
     */
    public function outdatedPackages(): array
    {
        return $this->outdatedPackages;
    }

    /**
     * @return list<Advisory>
     */
    public function advisories(): array
    {
        return $this->advisories;
    }

    /**
     * @return list<string>
     */
    public function advisoryKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (Advisory $advisory): string => $advisory->key(),
            $this->advisories,
        )));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<OutdatedPackage>
     */
    private static function parseOutdated(mixed $outdated): array
    {
        $map = JsonShape::asMap($outdated);

        if ($map === null) {
            return [];
        }

        $items = JsonShape::asList($map['locked'] ?? $map['installed'] ?? null) ?? [];
        $packages = [];

        foreach ($items as $item) {
            $package = is_array($item) ? OutdatedPackage::fromArray($item) : null;

            if ($package !== null) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * @param array<array-key, mixed>|null $audit
     * @return list<Advisory>
     */
    private static function parseAdvisories(?array $audit): array
    {
        $perPackage = JsonShape::asMap($audit['advisories'] ?? null) ?? [];
        $advisories = [];

        foreach ($perPackage as $items) {
            foreach (JsonShape::asList($items) ?? [] as $item) {
                $advisory = is_array($item) ? Advisory::fromArray($item) : null;

                if ($advisory !== null) {
                    $advisories[] = $advisory;
                }
            }
        }

        return $advisories;
    }
}
