<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Composer;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ComposerOutputParser;
use Webium\ComposerReport\Core\Security\TextSanitizer;
use Webium\ComposerReport\Tests\Core\Fixture;

class ComposerOutputParserTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const OUTDATED_ARGV = ['composer', 'outdated', '--locked', '--format=json'];
    private const AUDIT_ARGV = ['composer', 'audit', '--locked', '--format=json'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideOutdatedFixtures(): array
    {
        $cases = [];

        foreach (['vulnerable', 'clean', 'empty', 'abandoned-only'] as $scenario) {
            foreach (['outdated', 'outdated-direct'] as $label) {
                $cases["$scenario $label"] = [$scenario, $label];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider provideOutdatedFixtures
     */
    #[DataProvider('provideOutdatedFixtures')]
    public function testOutdatedFixturesAreValid(string $scenario, string $label): void
    {
        $section = $this->parser()->parseOutdated($this->fixtureResult($scenario, $label, self::OUTDATED_ARGV));

        $this->assertTrue($section->isAvailable());
        $this->assertNull($section->error);
        $this->assertSame(Fixture::json("composer/$scenario.$label.json"), $section->data);
    }

    public function testEmptyLockGivesABareList(): void
    {
        $section = $this->parser()->parseOutdated($this->fixtureResult('empty', 'outdated', self::OUTDATED_ARGV));

        $this->assertSame([], $section->data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAuditFixtures(): array
    {
        return [
            'vulnerable (exit 1)'     => ['vulnerable'],
            'abandoned-only (exit 1)' => ['abandoned-only'],
            'clean'                   => ['clean'],
        ];
    }

    /**
     * @dataProvider provideAuditFixtures
     */
    #[DataProvider('provideAuditFixtures')]
    public function testAuditFixturesAreValidWhateverTheExitCode(string $scenario): void
    {
        $section = $this->parser()->parseAudit($this->fixtureResult($scenario, 'audit', self::AUDIT_ARGV), false);

        $this->assertTrue($section->isAvailable());
        $this->assertSame(Fixture::json("composer/$scenario.audit.json"), $section->data);
        $this->assertSame([], $section->warnings);
    }

    public function testAuditOfAnEmptyLockIsCleanWhenOutdatedSawAnEmptyLock(): void
    {
        $section = $this->parser()->parseAudit($this->fixtureResult('empty', 'audit', self::AUDIT_ARGV), true);

        $this->assertSame(['advisories' => []], $section->data);
    }

    public function testAuditOfAnEmptyLockFailsOtherwise(): void
    {
        $section = $this->parser()->parseAudit($this->fixtureResult('empty', 'audit', self::AUDIT_ARGV), false);

        $this->assertFalse($section->isAvailable());
        $this->assertStringContainsString('found no packages', (string) $section->error);
    }

    public function testNetworkFailureOfTheAuditAddsTheCacheWarning(): void
    {
        $stderr = 'The "https://packagist.org/api/security-advisories/" file could not be downloaded';
        $result = new CommandResult(self::AUDIT_ARGV, 100, '', $stderr, 2.0);

        $section = $this->parser()->parseAudit($result, false);

        $this->assertFalse($section->isAvailable());
        $this->assertStringContainsString('gave no valid JSON (exit 100)', (string) $section->error);
        $this->assertSame([ComposerOutputParser::CACHE_WARNING], $section->warnings);
    }

    public function testNetworkMessageInStderrAlsoAddsTheCacheWarning(): void
    {
        $result = new CommandResult(self::AUDIT_ARGV, 1, '', 'https://repo.example.com could not be accessed', 2.0);

        $this->assertSame([ComposerOutputParser::CACHE_WARNING], $this->parser()->parseAudit($result, false)->warnings);
    }

    public function testOutdatedWithEmptyStdoutIsAnError(): void
    {
        $result = new CommandResult(self::OUTDATED_ARGV, 100, '', 'network down', 1.0);

        $section = $this->parser()->parseOutdated($result);

        $this->assertFalse($section->isAvailable());
        $this->assertSame(
            '`composer outdated --locked --format=json` gave no valid JSON (exit 100): network down',
            $section->error,
        );
        $this->assertSame([], $section->warnings);
    }

    public function testAuditObjectWithoutAdvisoriesIsAnError(): void
    {
        $result = new CommandResult(self::AUDIT_ARGV, 0, '{"something": []}', '', 1.0);

        $this->assertFalse($this->parser()->parseAudit($result, false)->isAvailable());
    }

    public function testMissingBinary(): void
    {
        $result = new CommandResult(self::OUTDATED_ARGV, 127, '', 'sh: 1: exec: composer: not found', 0.1);

        $error = (string) $this->parser()->parseOutdated($result)->error;

        $this->assertStringContainsString('Composer binary not found (exit 127)', $error);
        $this->assertStringContainsString('COMPOSER_REPORT_COMPOSER_BIN', $error);
    }

    public function testTimeout(): void
    {
        $result = new CommandResult(self::AUDIT_ARGV, null, '{"advis', '', 120.2, CommandResult::FAILURE_TIMEOUT);

        $section = $this->parser()->parseAudit($result, false);

        $this->assertSame('`composer audit --locked --format=json` timed out after 120 s', $section->error);
    }

    public function testProcessControlUnavailableAndNotStarted(): void
    {
        $unavailable = new CommandResult(
            self::AUDIT_ARGV,
            null,
            '',
            'Call to undefined function proc_get_status()',
            0.0,
            CommandResult::FAILURE_PROCESS_CONTROL_UNAVAILABLE,
        );
        $notStarted = new CommandResult(
            self::AUDIT_ARGV,
            null,
            '',
            'The provided cwd does not exist.',
            0.0,
            CommandResult::FAILURE_NOT_STARTED,
        );

        $unavailableError = (string) $this->parser()->parseAudit($unavailable, false)->error;
        $notStartedError = (string) $this->parser()->parseAudit($notStarted, false)->error;

        $this->assertStringContainsString('PHP process control is unavailable', $unavailableError);
        $this->assertStringContainsString('could not be started: The provided cwd', $notStartedError);
    }

    public function testErrorMessagesAreSanitized(): void
    {
        $stderr = "\e[31mFailed\e[0m with crt_0123456789abcdef in /var/www/site/vendor";
        $result = new CommandResult(['/var/www/site/bin/composer', 'outdated'], 1, 'not json', $stderr, 1.0);

        $error = (string) $this->parser('/var/www/site')->parseOutdated($result)->error;

        $this->assertSame(
            '`<project>/bin/composer outdated` gave no valid JSON (exit 1): Failed with crt_*** in <project>/vendor',
            $error,
        );
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideVersionOutput(): array
    {
        return [
            'current'   => ['Composer version 2.11.2 2026-11-10 10:09:37', '2.11.2'],
            'old style' => ['Composer 1.10.26 2022-04-13 16:39:56', '1.10.26'],
            'with php'  => ["Composer version 2.8.4 2024-12-11 11:57:47\nPHP version 8.3.36", '2.8.4'],
            'garbage'   => ['bash: composer: command not found', null],
        ];
    }

    /**
     * @dataProvider provideVersionOutput
     */
    #[DataProvider('provideVersionOutput')]
    public function testParseVersion(string $stdout, ?string $expected): void
    {
        $result = new CommandResult(['composer', '--version'], 0, $stdout, '', 0.1);

        $this->assertSame($expected, $this->parser()->parseVersion($result));
    }

    public function testParseVersionOfAFailedCommand(): void
    {
        $result = new CommandResult(
            ['composer', '--version'],
            null,
            'Composer version 2.0.0',
            '',
            0.1,
            CommandResult::FAILURE_TIMEOUT,
        );

        $this->assertNull($this->parser()->parseVersion($result));
    }

    private function parser(?string $basePath = null): ComposerOutputParser
    {
        return new ComposerOutputParser(new TextSanitizer($basePath));
    }

    /**
     * @param list<string> $argv
     */
    private function fixtureResult(string $scenario, string $label, array $argv): CommandResult
    {
        return new CommandResult(
            $argv,
            (int) trim(Fixture::read("composer/$scenario.$label.exitcode")),
            Fixture::read("composer/$scenario.$label.json"),
            Fixture::read("composer/$scenario.$label.stderr.txt"),
            1.0,
        );
    }
}
