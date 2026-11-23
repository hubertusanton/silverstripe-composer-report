<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\Controller;
use SilverStripe\Dev\TestOnly;

/**
 * Top-level controller for ProjectItemRequestTest. A ModelAdmin would need
 * init() with a model tab before it can build links.
 */
class TestAdminController extends Controller implements TestOnly
{
    private static string $url_segment = 'composer-report-test-admin';
}
