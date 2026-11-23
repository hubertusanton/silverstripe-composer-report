<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Report;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Report\Advisory;

class AdvisoryTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testFromArrayReadsAllFields(): void
    {
        $advisory = Advisory::fromArray([
            'advisoryId'       => 'PKSA-1',
            'packageName'      => 'vendor/package',
            'affectedVersions' => '<1.2.0',
            'title'            => 'Something bad',
            'cve'              => 'CVE-2026-1',
            'link'             => 'https://example.com/advisory',
            'reportedAt'       => '2026-01-01T00:00:00+00:00',
            'severity'         => 'high',
            'sources'          => [],
        ]);

        $this->assertNotNull($advisory);
        $this->assertSame('vendor/package', $advisory->packageName);
        $this->assertSame('high', $advisory->severity);
        $this->assertSame('PKSA-1', $advisory->key());
        $this->assertSame('CVE-2026-1', $advisory->displayId());
        $this->assertSame('https://example.com/advisory', $advisory->safeLink());
    }

    public function testFromArrayRequiresTheStringFields(): void
    {
        $this->assertNull(Advisory::fromArray(['packageName' => 'a/b', 'title' => 'x']));
        $this->assertNull(Advisory::fromArray(['packageName' => 'a/b', 'title' => 1, 'affectedVersions' => '*']));
    }

    public function testDisplayIdWithoutCve(): void
    {
        $withId = Advisory::fromArray([
            'packageName'      => 'a/b',
            'title'            => 'x',
            'affectedVersions' => '*',
            'advisoryId'       => 'PKSA-2',
            'cve'              => null,
            'link'             => 'https://example.com',
        ]);
        $bare = new Advisory('a/b', 'x', '*');

        $this->assertSame('PKSA-2', $withId?->displayId());
        $this->assertSame('https://example.com', $withId?->safeLink());
        $this->assertSame('-', $bare->displayId());
    }

    public function testSafeLinkOnlyAllowsHttp(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//example.com', 'ftp://example.com'] as $link) {
            $advisory = new Advisory('a/b', 'x', '*', null, null, $link);
            $this->assertNull($advisory->safeLink(), $link);
        }

        $upperCase = new Advisory('a/b', 'x', '*', null, null, 'HTTP://example.com');
        $this->assertSame('HTTP://example.com', $upperCase->safeLink());
        $this->assertNull((new Advisory('a/b', 'x', '*'))->safeLink());
    }

    public function testKeyFallsBackToAStableHash(): void
    {
        $one = new Advisory('a/b', 'Title', '<2', null, 'CVE-1');
        $same = new Advisory('a/b', 'Title', '<2', null, 'CVE-1', 'https://other.example');
        $other = new Advisory('a/b', 'Title', '<3', null, 'CVE-1');

        $this->assertSame(40, strlen($one->key()));
        $this->assertSame($one->key(), $same->key());
        $this->assertNotSame($one->key(), $other->key());
    }
}
