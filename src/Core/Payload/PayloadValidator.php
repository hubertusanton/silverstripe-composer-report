<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Payload;

use Webium\ComposerReport\Core\Report\JsonShape;

/**
 * Two levels: the envelope is strict (an error means the payload is rejected),
 * the Composer sections are tolerant (an invalid section is dropped, the
 * report is still stored).
 */
final class PayloadValidator
{
    // D: "$" must not match before a trailing newline
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    public const SLUG_MAX_LENGTH = 64;

    private const ENVIRONMENT_KEYS = ['php_version', 'composer_version', 'silverstripe_framework', 'hostname'];
    private const ADVISORY_REQUIRED = ['title', 'packageName', 'affectedVersions'];
    private const ADVISORY_OPTIONAL = ['advisoryId', 'cve', 'link', 'severity', 'reportedAt'];

    /**
     * @return list<string> empty when the envelope is valid
     */
    public function validateEnvelope(mixed $payload): array
    {
        $payload = JsonShape::asMap($payload);

        if ($payload === null) {
            return ['payload must be a JSON object'];
        }

        $errors = [];
        $schemaVersion = $payload['schema_version'] ?? null;

        if (!is_int($schemaVersion) || $schemaVersion < 1 || $schemaVersion > PayloadBuilder::SCHEMA_VERSION) {
            $errors[] = sprintf('schema_version must be an integer from 1 to %d', PayloadBuilder::SCHEMA_VERSION);
        }

        if (!self::isValidSlug($payload['project'] ?? null)) {
            $errors[] = sprintf(
                'project must be a slug (lowercase letters, digits and single hyphens) of at most %d characters',
                self::SLUG_MAX_LENGTH,
            );
        }

        if (!self::isAtomDate($payload['generated_at'] ?? null)) {
            $errors[] = 'generated_at must be a date in ATOM format (e.g. 2026-11-21T03:00:00+01:00)';
        }

        $errors = [...$errors, ...$this->validateEnvironment($payload['environment'] ?? null)];
        $errors = [...$errors, ...$this->validateErrors($payload['errors'] ?? null)];

        foreach (['outdated', 'audit'] as $section) {
            if (!array_key_exists($section, $payload)) {
                $errors[] = $section . ' is missing';
            } elseif ($payload[$section] !== null && !is_array($payload[$section])) {
                $errors[] = $section . ' must be null or a JSON object or array';
            }
        }

        if (
            array_key_exists('outdated', $payload) && $payload['outdated'] === null
            && array_key_exists('audit', $payload) && $payload['audit'] === null
            && (!is_array($payload['errors'] ?? null) || $payload['errors'] === [])
        ) {
            $errors[] = 'errors must explain why both outdated and audit are null';
        }

        return $errors;
    }

    /**
     * Shape of the Composer sections. Call after validateEnvelope() succeeded.
     *
     * @param array<array-key, mixed> $payload
     */
    public function validateSections(array $payload): SectionValidation
    {
        return new SectionValidation(
            $this->validateOutdated($payload['outdated'] ?? null),
            $this->validateAudit($payload['audit'] ?? null),
        );
    }

    public static function isValidSlug(mixed $slug): bool
    {
        return is_string($slug)
            && strlen($slug) <= self::SLUG_MAX_LENGTH
            && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    private static function isAtomDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $problems = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0));
    }

    /**
     * @return list<string>
     */
    private function validateEnvironment(mixed $environment): array
    {
        $environment = JsonShape::asMap($environment);

        if ($environment === null) {
            return ['environment must be a JSON object'];
        }

        $errors = [];

        foreach (self::ENVIRONMENT_KEYS as $key) {
            if (!array_key_exists($key, $environment)) {
                $errors[] = 'environment.' . $key . ' is missing';
                continue;
            }

            $value = $environment[$key];

            if ($value !== null && (!is_string($value) || mb_strlen($value) > EnvironmentInfo::MAX_LENGTH)) {
                $errors[] = sprintf(
                    'environment.%s must be null or a string of at most %d characters',
                    $key,
                    EnvironmentInfo::MAX_LENGTH,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateErrors(mixed $errors): array
    {
        $list = JsonShape::asList($errors);

        if ($list === null) {
            return ['errors must be a list'];
        }

        if (count($list) > PayloadBuilder::MAX_ERRORS) {
            return [sprintf('errors may hold at most %d entries', PayloadBuilder::MAX_ERRORS)];
        }

        foreach ($list as $error) {
            if (!is_string($error) || mb_strlen($error) > PayloadBuilder::MAX_ERROR_LENGTH) {
                return [sprintf('errors must hold strings of at most %d characters', PayloadBuilder::MAX_ERROR_LENGTH)];
            }
        }

        return [];
    }

    private function validateOutdated(mixed $outdated): ?string
    {
        if ($outdated === null || $outdated === []) {
            return null;
        }

        $map = JsonShape::asMap($outdated);

        if ($map === null) {
            return 'outdated section rejected: expected an object with "locked" or "installed"';
        }

        $key = array_key_exists('locked', $map) ? 'locked' : (array_key_exists('installed', $map) ? 'installed' : null);
        $items = $key === null ? null : JsonShape::asList($map[$key]);

        if ($items === null) {
            return 'outdated section rejected: "locked" or "installed" must be a list';
        }

        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['version'] ?? null)) {
                return sprintf('outdated section rejected: %s[%d] needs a string name and version', $key, $index);
            }
        }

        return null;
    }

    private function validateAudit(mixed $audit): ?string
    {
        if ($audit === null) {
            return null;
        }

        $map = JsonShape::asMap($audit);

        if ($map === null || !array_key_exists('advisories', $map)) {
            return 'audit section rejected: expected an object with "advisories"';
        }

        $perPackage = JsonShape::asMap($map['advisories']);

        if ($perPackage === null) {
            return 'audit section rejected: "advisories" must map package names to lists';
        }

        foreach ($perPackage as $package => $advisories) {
            $list = JsonShape::asList($advisories);

            if ($list === null) {
                return sprintf('audit section rejected: advisories of %s must be a list', $package);
            }

            foreach ($list as $index => $advisory) {
                $problem = $this->advisoryProblem($advisory);

                if ($problem !== null) {
                    return sprintf('audit section rejected: advisory %s[%d] %s', $package, $index, $problem);
                }
            }
        }

        return null;
    }

    private function advisoryProblem(mixed $advisory): ?string
    {
        if (!is_array($advisory)) {
            return 'must be an object';
        }

        foreach (self::ADVISORY_REQUIRED as $key) {
            if (!is_string($advisory[$key] ?? null)) {
                return 'needs a string ' . $key;
            }
        }

        foreach (self::ADVISORY_OPTIONAL as $key) {
            if (isset($advisory[$key]) && !is_string($advisory[$key])) {
                return 'has a non-string ' . $key;
            }
        }

        return null;
    }
}
