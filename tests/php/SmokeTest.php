<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests;

use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Dev\SapphireTest;

class SmokeTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testModuleIsRegistered(): void
    {
        $module = ModuleLoader::inst()->getManifest()->getModule('webium/silverstripe-composer-report');

        $this->assertNotNull($module, 'webium/silverstripe-composer-report is not in the module manifest');
    }
}
