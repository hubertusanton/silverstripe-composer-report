<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Client;

use SilverStripe\Dev\TestOnly;
use Webium\ComposerReport\Client\ComposerReportTask;

/**
 * Records the exit code instead of calling exit().
 */
class TestComposerReportTask extends ComposerReportTask implements TestOnly
{
    public ?int $terminatedWith = null;

    protected function terminate(int $exitCode): void
    {
        $this->terminatedWith = $exitCode;
    }
}
