<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Report;

/**
 * One advisory from the "advisories" map of `composer audit --format=json`.
 */
final class Advisory
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $title,
        public readonly string $affectedVersions,
        public readonly ?string $advisoryId = null,
        public readonly ?string $cve = null,
        public readonly ?string $link = null,
        public readonly ?string $severity = null,
        public readonly ?string $reportedAt = null,
    ) {
    }

    /**
     * Null when one of the required string fields is missing.
     *
     * @param array<array-key, mixed> $item
     */
    public static function fromArray(array $item): ?self
    {
        foreach (['packageName', 'title', 'affectedVersions'] as $required) {
            if (!is_string($item[$required] ?? null)) {
                return null;
            }
        }

        return new self(
            $item['packageName'],
            $item['title'],
            $item['affectedVersions'],
            self::stringOrNull($item['advisoryId'] ?? null),
            self::stringOrNull($item['cve'] ?? null),
            self::stringOrNull($item['link'] ?? null),
            self::stringOrNull($item['severity'] ?? null),
            self::stringOrNull($item['reportedAt'] ?? null),
        );
    }

    /**
     * Stable identity for diffing: the advisoryId, otherwise a hash of the
     * fields that identify the advisory.
     */
    public function key(): string
    {
        if ($this->advisoryId !== null) {
            return $this->advisoryId;
        }

        return sha1(implode('|', [$this->packageName, $this->cve ?? '', $this->title, $this->affectedVersions]));
    }

    public function displayId(): string
    {
        return $this->cve ?? $this->advisoryId ?? '-';
    }

    /**
     * The link, but only for http(s); anything else (javascript:, data:) gives null.
     */
    public function safeLink(): ?string
    {
        if ($this->link === null || !preg_match('#^https?://#i', $this->link)) {
            return null;
        }

        return $this->link;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
