<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Report;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Report\Advisory;
use Webium\ComposerReport\Core\Report\AdvisoryDiff;

class AdvisoryDiffTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testIdenticalReportGivesNothing(): void
    {
        $current = [$this->advisory('PKSA-1'), $this->advisory('PKSA-2')];

        $this->assertSame([], (new AdvisoryDiff())->newAdvisories(['PKSA-1', 'PKSA-2'], $current));
    }

    public function testOneNewAdvisory(): void
    {
        $new = $this->advisory('PKSA-3');

        $result = (new AdvisoryDiff())->newAdvisories(['PKSA-1'], [$this->advisory('PKSA-1'), $new]);

        $this->assertSame([$new], $result);
    }

    public function testResolvedAdvisoryGivesNothing(): void
    {
        $this->assertSame([], (new AdvisoryDiff())->newAdvisories(['PKSA-1', 'PKSA-2'], [$this->advisory('PKSA-1')]));
    }

    public function testEverythingIsNewWithoutPreviousKeys(): void
    {
        $current = [$this->advisory('PKSA-1'), $this->advisory('PKSA-2')];

        $this->assertSame($current, (new AdvisoryDiff())->newAdvisories([], $current));
    }

    public function testFallbackKeyWithoutAdvisoryId(): void
    {
        $known = new Advisory('a/b', 'Old issue', '<1', null, 'CVE-1');
        $new = new Advisory('a/b', 'New issue', '<1', null, null);

        $result = (new AdvisoryDiff())->newAdvisories([$known->key()], [$known, $new]);

        $this->assertSame([$new], $result);
    }

    public function testDuplicateKeysAreReportedOnce(): void
    {
        $first = $this->advisory('PKSA-1');

        $this->assertSame([$first], (new AdvisoryDiff())->newAdvisories([], [$first, $this->advisory('PKSA-1')]));
    }

    private function advisory(string $id): Advisory
    {
        return new Advisory('vendor/package', 'Title ' . $id, '<1.0', $id);
    }
}
