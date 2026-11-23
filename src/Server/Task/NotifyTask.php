<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Task;

use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use Webium\ComposerReport\Server\Notification\NotificationService;
use Webium\ComposerReport\Server\ServerActivation;

/**
 * vendor/bin/sake dev/tasks/composer-report-notify, on the monitor every 15 minutes.
 * Queues stale and "audit failing" notifications, sends everything pending and
 * removes old rows. Disabled on clients; server-enabled.yml enables it.
 */
class NotifyTask extends BuildTask
{
    private static $segment = 'composer-report-notify';

    private static ?bool $is_enabled = false;

    protected $title = 'Composer report notifications';

    protected $description = 'Sends Composer monitor notifications: new advisories, stale projects '
        . 'and failing audits';

    public function canView(): bool
    {
        return Director::is_cli();
    }

    public function run($request)
    {
        $exitCode = $this->runWithExitCode();

        if ($exitCode !== 0) {
            $this->terminate($exitCode);
        }
    }

    public function runWithExitCode(): int
    {
        if (!ServerActivation::isEnabled()) {
            echo 'The Composer monitor is not enabled (COMPOSER_REPORT_SERVER_ENABLED).', PHP_EOL;

            return 1;
        }

        /** @var NotificationService $service */
        $service = Injector::inst()->get(NotificationService::class);

        $stale = $service->queueStale();
        $auditFailing = $service->queueAuditFailing();
        $result = $service->sendPending();
        $removed = $service->cleanUp();

        echo sprintf(
            'Queued %d stale and %d audit-failing notifications; sent %d, failed %d; removed %d old rows.',
            $stale,
            $auditFailing,
            $result['sent'],
            $result['failed'],
            $removed,
        ), PHP_EOL;

        // Non-zero so cron reports a failed delivery
        return $result['failed'] > 0 ? 1 : 0;
    }

    /**
     * Ends the process with the exit code; overridden in tests.
     */
    protected function terminate(int $exitCode): void
    {
        exit($exitCode);
    }
}
