<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use RuntimeException;
use SilverStripe\Dev\TestOnly;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\Notification\NotificationChannel;

/**
 * Fails a given number of times, then records what it delivers.
 */
class FlakyChannel implements NotificationChannel, TestOnly
{
    /**
     * @var list<array{type: string, project: string, advisories: list<array<string, mixed>>}>
     */
    public array $delivered = [];

    public function __construct(
        private int $failures = 0,
    ) {
    }

    public function send(string $type, ComposerReportProject $project, array $advisories): void
    {
        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('SMTP down for crt_0123456789abcdef');
        }

        $this->delivered[] = ['type' => $type, 'project' => (string) $project->Slug, 'advisories' => $advisories];
    }
}
