<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Report\ReportSummary;
use Webium\ComposerReport\Core\Report\UpdateLevel;
use Webium\ComposerReport\Tests\Core\Fixture;

/**
 * The expected counts come from the generated fixtures (tests/fixtures/composer,
 * see GENERATED.txt). Update them when the fixtures are regenerated.
 */
class ReportSummaryTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * outdated, advisories, advisory packages, abandoned
     *
     * @return array<string, array{string, string, int, int, int, int}>
     */
    public static function provideFixtures(): array
    {
        return [
            'vulnerable'              => ['vulnerable', 'outdated', 6, 11, 2, 1],
            'vulnerable, direct only' => ['vulnerable', 'outdated-direct', 2, 11, 2, 1],
            'abandoned only'          => ['abandoned-only', 'outdated', 0, 0, 0, 1],
            'clean'                   => ['clean', 'outdated', 0, 0, 0, 0],
        ];
    }

    /**
     * @dataProvider provideFixtures
     */
    #[DataProvider('provideFixtures')]
    public function testCountsFromGeneratedFixtures(
        string $scenario,
        string $outdatedFile,
        int $outdated,
        int $advisories,
        int $advisoryPackages,
        int $abandoned,
    ): void {
        $summary = ReportSummary::fromPayload([
            'outdated' => Fixture::json("composer/$scenario.$outdatedFile.json"),
            'audit'    => Fixture::json("composer/$scenario.audit.json"),
            'errors'   => [],
        ]);

        $this->assertTrue($summary->isOutdatedAvailable());
        $this->assertTrue($summary->isAuditAvailable());
        $this->assertSame($outdated, $summary->outdatedCount());
        $this->assertSame($advisories, $summary->advisoryCount());
        $this->assertSame($advisoryPackages, $summary->advisoryPackageCount());
        $this->assertSame($abandoned, $summary->abandonedCount());
        $this->assertCount($advisories, $summary->advisoryKeys());
    }

    public function testEmptyLock(): void
    {
        $summary = ReportSummary::fromPayload([
            'outdated' => Fixture::json('composer/empty.outdated.json'),
            'audit'    => ['advisories' => []],
            'errors'   => [],
        ]);

        $this->assertTrue($summary->isOutdatedAvailable());
        $this->assertSame(0, $summary->outdatedCount());
        $this->assertSame([], $summary->outdatedPackages());
        $this->assertSame(0, $summary->advisoryCount());
        $this->assertSame(0, $summary->abandonedCount());
    }

    public function testOutdatedRows(): void
    {
        $summary = ReportSummary::fromPayload([
            'outdated' => Fixture::json('composer/vulnerable.outdated.json'),
            'audit'    => null,
            'errors'   => ['audit failed'],
        ]);

        $byName = [];
        foreach ($summary->outdatedPackages() as $package) {
            $byName[$package->name] = $package;
        }

        $this->assertSame(UpdateLevel::Major, $byName['dompdf/dompdf']->updateLevel());
        $this->assertSame(UpdateLevel::Major, $byName['psr/http-message']->updateLevel());
        $this->assertTrue($byName['php-http/message-factory']->abandoned);
        $this->assertSame('psr/http-factory', $byName['php-http/message-factory']->replacement);
        $this->assertFalse($byName['php-http/message-factory']->isOutdated());
        $this->assertFalse($summary->isAuditAvailable());
        $this->assertSame(['audit failed'], $summary->errors());
    }

    public function testIgnoredAdvisoriesDoNotCount(): void
    {
        $advisory = [
            'advisoryId'       => 'PKSA-1',
            'packageName'      => 'a/b',
            'title'            => 'x',
            'affectedVersions' => '<1',
        ];

        $summary = ReportSummary::fromPayload([
            'outdated' => null,
            'audit'    => [
                'advisories'         => ['a/b' => [$advisory]],
                'ignored-advisories' => ['a/b' => [array_merge($advisory, ['advisoryId' => 'PKSA-2'])]],
            ],
            'errors'   => ['outdated failed'],
        ]);

        $this->assertSame(1, $summary->advisoryCount());
        $this->assertSame(['PKSA-1'], $summary->advisoryKeys());
    }

    public function testAbandonedFallsBackToOutdatedItemsWithoutAuditKey(): void
    {
        $summary = ReportSummary::fromPayload([
            'outdated' => Fixture::json('composer/vulnerable.outdated.json'),
            'audit'    => ['advisories' => []],
            'errors'   => [],
        ]);

        $this->assertSame(1, $summary->abandonedCount());
    }

    public function testAuditAbandonedWinsOverOutdatedItems(): void
    {
        $summary = ReportSummary::fromPayload([
            'outdated' => Fixture::json('composer/vulnerable.outdated.json'),
            'audit'    => ['advisories' => [], 'abandoned' => []],
            'errors'   => [],
        ]);

        $this->assertSame(0, $summary->abandonedCount());
    }

    public function testUnexpectedShapesGiveEmptyResults(): void
    {
        $summary = ReportSummary::fromPayload([
            'outdated' => ['locked' => 'nope'],
            'audit'    => ['advisories' => ['a/b' => 'nope', 'c/d' => [['title' => 'no package']]]],
            'errors'   => ['ok', 3],
        ]);

        $this->assertSame(0, $summary->outdatedCount());
        $this->assertSame(0, $summary->advisoryCount());
        $this->assertSame(['ok'], $summary->errors());
    }

    public function testMissingSections(): void
    {
        $summary = ReportSummary::fromPayload([]);

        $this->assertFalse($summary->isOutdatedAvailable());
        $this->assertFalse($summary->isAuditAvailable());
        $this->assertSame(0, $summary->abandonedCount());
    }
}
