<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Fakes;

use Webium\ComposerReport\Core\Composer\CommandResult;
use Webium\ComposerReport\Core\Composer\ComposerRunner;
use Webium\ComposerReport\Tests\Core\Fixture;

/**
 * Returns prepared results and records which commands ran.
 */
final class FakeComposerRunner implements ComposerRunner
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        private readonly CommandResult $outdated,
        private readonly CommandResult $audit,
        private readonly ?string $unavailableReason = null,
        private readonly array $warnings = [],
        private readonly ?CommandResult $version = null,
    ) {
    }

    /**
     * Results from the generated fixtures of one scenario (tests/fixtures/composer).
     */
    public static function fromFixtures(string $scenario, bool $directOnly = true): self
    {
        $outdatedLabel = $directOnly ? 'outdated-direct' : 'outdated';

        return new self(
            self::fixtureResult($scenario, $outdatedLabel, ['composer', 'outdated', '--locked', '--format=json']),
            self::fixtureResult($scenario, 'audit', ['composer', 'audit', '--locked', '--format=json']),
        );
    }

    /**
     * @param list<string> $argv
     */
    public static function fixtureResult(string $scenario, string $label, array $argv): CommandResult
    {
        return new CommandResult(
            $argv,
            (int) trim(Fixture::read("composer/$scenario.$label.exitcode")),
            Fixture::read("composer/$scenario.$label.json"),
            Fixture::read("composer/$scenario.$label.stderr.txt"),
            0.5,
        );
    }

    public function unavailableReason(): ?string
    {
        $this->calls[] = 'unavailableReason';

        return $this->unavailableReason;
    }

    public function environmentWarnings(): array
    {
        return $this->warnings;
    }

    public function version(): CommandResult
    {
        $this->calls[] = 'version';

        return $this->version ?? new CommandResult(
            ['composer', '--version', '--no-ansi'],
            0,
            'Composer version 2.11.2 2026-11-10 10:09:37',
            '',
            0.1,
        );
    }

    public function outdated(bool $directOnly): CommandResult
    {
        $this->calls[] = $directOnly ? 'outdated --direct' : 'outdated';

        return $this->outdated;
    }

    public function audit(): CommandResult
    {
        $this->calls[] = 'audit';

        return $this->audit;
    }
}
