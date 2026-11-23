<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Dev\SapphireTest;
use SplFileInfo;

/**
 * src/Core must stay plain PHP, identical on branch 1 (CMS 5) and 2 (CMS 6).
 */
class CoreIsFrameworkAgnosticTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testCoreDoesNotReferenceSilverstripe(): void
    {
        $core = dirname(__DIR__, 3) . '/src/Core';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($core, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $checked = 0;

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $checked++;
            $this->assertDoesNotMatchRegularExpression(
                '/\bSilverStripe\\\\/i',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' references SilverStripe',
            );
        }

        $this->assertGreaterThan(20, $checked, 'the Core directory was found');
    }
}
