<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Dev\TestOnly;
use Webium\ComposerReport\Server\Task\NotifyTask;

/**
 * Records the exit code instead of calling exit().
 */
class TestNotifyTask extends NotifyTask implements TestOnly
{
    public ?int $terminatedWith = null;

    protected function terminate(int $exitCode): void
    {
        $this->terminatedWith = $exitCode;
    }
}
