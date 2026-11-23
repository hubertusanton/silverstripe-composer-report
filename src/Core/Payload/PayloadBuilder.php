<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Payload;

use Webium\ComposerReport\Core\Composer\ParsedSection;

final class PayloadBuilder
{
    public const SCHEMA_VERSION = 1;
    public const MAX_ERRORS = 50;
    public const MAX_ERROR_LENGTH = 2000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * The errors list holds $errors first, then the error and warnings of each
     * section; duplicates are dropped and the list is capped.
     *
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    public function build(
        string $project,
        EnvironmentInfo $environment,
        ParsedSection $outdated,
        ParsedSection $audit,
        array $errors,
        \DateTimeImmutable $generatedAt,
    ): array {
        foreach ([$outdated, $audit] as $section) {
            if ($section->error !== null) {
                $errors[] = $section->error;
            }

            array_push($errors, ...$section->warnings);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'project'        => $project,
            'generated_at'   => $generatedAt->format(DATE_ATOM),
            'environment'    => $environment->toArray(),
            'outdated'       => $outdated->data,
            'audit'          => $audit->data,
            'errors'         => self::limitErrors($errors),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \JsonException
     */
    public function encode(array $payload, bool $pretty = false): string
    {
        return json_encode($payload, self::JSON_FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    /**
     * Unique, at most MAX_ERRORS entries of at most MAX_ERROR_LENGTH characters.
     *
     * @param list<string> $errors
     * @return list<string>
     */
    public static function limitErrors(array $errors): array
    {
        $limited = [];

        foreach ($errors as $error) {
            $error = mb_substr(mb_scrub($error, 'UTF-8'), 0, self::MAX_ERROR_LENGTH);

            if (!in_array($error, $limited, true)) {
                $limited[] = $error;
            }
        }

        return array_slice($limited, 0, self::MAX_ERRORS);
    }
}
