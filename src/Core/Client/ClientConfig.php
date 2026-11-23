<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Client;

use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Core\Security\Secret;

final class ClientConfig
{
    /**
     * @param list<string> $composerCommand
     */
    public function __construct(
        public readonly ?string $endpoint,
        public readonly ?string $project,
        public readonly ?Secret $token,
        public readonly array $composerCommand,
        public readonly float $timeout,
        public readonly float $httpTimeout,
        public readonly bool $directOnly,
        public readonly string $basePath,
        public readonly ?string $composerHomeFallback,
    ) {
    }

    /**
     * A dry run only needs a project; sending also needs an https endpoint and a token.
     *
     * @return list<string>
     */
    public function validate(bool $dryRun): array
    {
        $errors = [];

        if ($this->project === null || $this->project === '') {
            $errors[] = 'COMPOSER_REPORT_PROJECT is not set.';
        } elseif (!PayloadValidator::isValidSlug($this->project)) {
            $errors[] = sprintf(
                'COMPOSER_REPORT_PROJECT must be a slug '
                . '(lowercase letters, digits and single hyphens, at most %d characters).',
                PayloadValidator::SLUG_MAX_LENGTH,
            );
        }

        if (!$dryRun) {
            if ($this->endpoint === null || $this->endpoint === '') {
                $errors[] = 'COMPOSER_REPORT_ENDPOINT is not set.';
            } elseif (!self::isHttpsUrl($this->endpoint)) {
                $errors[] = 'COMPOSER_REPORT_ENDPOINT must be an https:// URL.';
            }

            if ($this->token === null || $this->token->reveal() === '') {
                $errors[] = 'COMPOSER_REPORT_TOKEN is not set.';
            }
        }

        if ($this->composerCommand === [] || in_array('', $this->composerCommand, true)) {
            $errors[] = 'composer_bin must not be empty.';
        }

        if ($this->timeout <= 0) {
            $errors[] = 'timeout must be greater than 0.';
        }

        if ($this->httpTimeout <= 0) {
            $errors[] = 'http_timeout must be greater than 0.';
        }

        return $errors;
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== '';
    }
}
