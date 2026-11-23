<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Security\TextSanitizer;

class TextSanitizerTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRedactions(): array
    {
        return [
            'project token'   => ['token crt_0123456789abcdef0123 rejected', 'token crt_*** rejected'],
            'url credentials' => ['https://user:p4ss@repo.example.com/x', 'https://***@repo.example.com/x'],
            'url token only'  => ['https://ghtoken@github.com/x', 'https://***@github.com/x'],
            'github token'    => ['auth ghp_abcDEF123456', 'auth ***'],
            'github pat'      => ['auth github_pat_11ABC_def', 'auth ***'],
            'gitlab token'    => ['auth glpat-abc_DEF-123', 'auth ***'],
            'bearer header'   => ['Authorization: Bearer abc.def', 'Authorization: Bearer ***'],
            'ansi colours'    => ["\e[31mError\e[0m: failed", 'Error: failed'],
            'control chars'   => ["a\x07b\x00c\rd", 'abcd'],
            'newlines kept'   => ["line 1\nline 2\tx", "line 1\nline 2\tx"],
            'plain text'      => ['nothing to hide', 'nothing to hide'],
        ];
    }

    /**
     * @dataProvider provideRedactions
     */
    #[DataProvider('provideRedactions')]
    public function testRedact(string $input, string $expected): void
    {
        $this->assertSame($expected, (new TextSanitizer())->redact($input));
    }

    public function testBasePathIsReplaced(): void
    {
        $sanitizer = new TextSanitizer('/var/www/klant-x/');

        $this->assertSame(
            'In <project>/composer.json: failed',
            $sanitizer->redact('In /var/www/klant-x/composer.json: failed'),
        );
    }

    public function testRootBasePathIsIgnored(): void
    {
        $this->assertSame('/usr/bin/composer', (new TextSanitizer('/'))->redact('/usr/bin/composer'));
    }

    public function testStderrExcerptIsTrimmedAndCut(): void
    {
        $sanitizer = new TextSanitizer();

        $this->assertSame('short', $sanitizer->stderrExcerpt("  short\n"));

        $excerpt = $sanitizer->stderrExcerpt(str_repeat('a', 600));
        $this->assertSame(500, strlen($excerpt));
        $this->assertStringEndsWith('...', $excerpt);
    }

    public function testStderrExcerptDoesNotBreakUtf8(): void
    {
        $sanitizer = new TextSanitizer();

        for ($offset = 0; $offset < 4; $offset++) {
            $input = str_repeat('x', $offset) . str_repeat('€', 300);
            $excerpt = $sanitizer->stderrExcerpt($input, 100);

            $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'), 'offset ' . $offset);
            $this->assertLessThanOrEqual(100, strlen($excerpt));
        }
    }

    public function testStderrExcerptScrubsInvalidUtf8(): void
    {
        $excerpt = (new TextSanitizer())->stderrExcerpt("bad \xC3\x28 bytes");

        $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        $this->assertStringStartsWith('bad ', $excerpt);
    }
}
