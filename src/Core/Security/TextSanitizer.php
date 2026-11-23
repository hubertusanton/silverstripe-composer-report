<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Core\Security;

/**
 * Removes secrets, the project path and terminal noise from text that ends up
 * in output, payloads or logs.
 */
final class TextSanitizer
{
    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/crt_[0-9a-f]{4,}/i' => 'crt_***',
        '#://[^/\s@]+@#'      => '://***@',
        '/github_pat_\w+/'    => '***',
        '/ghp_\w+/'           => '***',
        '/glpat-[\w-]+/'      => '***',
        '/Bearer\s+\S+/i'     => 'Bearer ***',
    ];

    public function __construct(
        private readonly ?string $basePath = null,
    ) {
    }

    public function redact(string $text): string
    {
        // ANSI escape sequences (CSI and OSC), then the remaining control characters except tab and newline
        $text = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $text) ?? '';
        $text = preg_replace('/\e\][^\a\e]*(?:\a|\e\\\\)/', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';

        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? '';
        }

        $basePath = rtrim((string) $this->basePath, '/');

        if ($basePath !== '') {
            $text = str_replace($basePath, '<project>', $text);
        }

        return $text;
    }

    /**
     * Redacted, trimmed and cut to at most $maxBytes without breaking a UTF-8 character.
     */
    public function stderrExcerpt(string $stderr, int $maxBytes = 500): string
    {
        $text = trim(mb_scrub($this->redact($stderr), 'UTF-8'));

        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return rtrim(mb_strcut($text, 0, max(0, $maxBytes - 3), 'UTF-8')) . '...';
    }
}
