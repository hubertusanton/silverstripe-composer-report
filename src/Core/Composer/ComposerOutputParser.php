<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Composer;

use Webium\ComposerReport\Core\Report\JsonShape;
use Webium\ComposerReport\Core\Security\TextSanitizer;

/**
 * Turns Composer output into payload sections. The exit code only matters for
 * the error message: valid JSON is used whatever the exit code, because its
 * meaning differs per Composer version.
 */
final class ComposerOutputParser
{
    public const CACHE_WARNING = 'composer audit could not reach the package repositories; '
        . 'the outdated data may come from the Composer cache';

    public function __construct(
        private readonly TextSanitizer $sanitizer,
    ) {
    }

    public function parseVersion(CommandResult $result): ?string
    {
        if ($result->failure !== null || !preg_match('/Composer (?:version )?(\S+)/', $result->stdout, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Valid when stdout decodes to an array: a bare [] for an empty lock, or {"locked": [...]}.
     */
    public function parseOutdated(CommandResult $result): ParsedSection
    {
        if ($result->failure === null) {
            $data = $this->decode($result->stdout);

            if (is_array($data)) {
                return ParsedSection::ok($data);
            }
        }

        return ParsedSection::failed($this->failureMessage($result));
    }

    /**
     * Valid when stdout is an object with "advisories". An empty stdout with
     * "No packages - skipping audit" counts as a clean audit, but only when the
     * outdated output also showed an empty lock.
     */
    public function parseAudit(CommandResult $result, bool $lockIsEmpty): ParsedSection
    {
        $warnings = $this->isNetworkFailure($result) ? [self::CACHE_WARNING] : [];

        if ($result->failure !== null) {
            return ParsedSection::failed($this->failureMessage($result), $warnings);
        }

        $data = JsonShape::asMap($this->decode($result->stdout));

        if ($data !== null && array_key_exists('advisories', $data)) {
            return ParsedSection::ok($data);
        }

        if (trim($result->stdout) === '' && str_contains($result->stderr, 'No packages - skipping audit')) {
            if ($lockIsEmpty) {
                return ParsedSection::ok(['advisories' => []]);
            }

            return ParsedSection::failed(sprintf(
                '`%s` found no packages, but composer outdated did not report an empty lock file',
                $this->sanitizer->redact($result->commandLine()),
            ));
        }

        return ParsedSection::failed($this->failureMessage($result), $warnings);
    }

    private function decode(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function isNetworkFailure(CommandResult $result): bool
    {
        return $result->exitCode === 100
            || stripos($result->stderr, 'network') !== false
            || str_contains($result->stderr, 'could not be accessed');
    }

    private function failureMessage(CommandResult $result): string
    {
        $command = $this->sanitizer->redact($result->commandLine());
        $excerpt = $this->sanitizer->stderrExcerpt($result->stderr);
        $suffix = $excerpt === '' ? '' : ': ' . $excerpt;

        return match (true) {
            $result->failure === CommandResult::FAILURE_TIMEOUT
                => sprintf('`%s` timed out after %.0f s', $command, $result->duration),
            $result->failure === CommandResult::FAILURE_PROCESS_CONTROL_UNAVAILABLE
                => sprintf('`%s` could not run, PHP process control is unavailable%s', $command, $suffix),
            $result->failure !== null
                => sprintf('`%s` could not be started%s', $command, $suffix),
            $result->exitCode === 127
                => sprintf(
                    '`%s` failed: Composer binary not found (exit 127); '
                    . 'set composer_bin or COMPOSER_REPORT_COMPOSER_BIN%s',
                    $command,
                    $suffix,
                ),
            default
                => sprintf('`%s` gave no valid JSON (exit %d)%s', $command, (int) $result->exitCode, $suffix),
        };
    }
}
