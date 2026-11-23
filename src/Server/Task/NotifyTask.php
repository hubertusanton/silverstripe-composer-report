<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Task;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;
use Webium\ComposerReport\Server\Notification\NotificationService;
use Webium\ComposerReport\Server\ServerActivation;

/**
 * vendor/bin/sake tasks:composer-report-notify, on the monitor every 15 minutes.
 * Queues stale and "audit failing" notifications, sends everything pending and
 * removes old rows. Disabled on clients; server-enabled.yml enables it.
 */
class NotifyTask extends BuildTask
{
    protected static string $commandName = 'composer-report-notify';

    protected string $title = 'Composer report notifications';

    protected static string $description = 'Sends Composer monitor notifications: new advisories, stale projects '
        . 'and failing audits';

    private static bool $is_enabled = false;

    public static function canRunInBrowser(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (!ServerActivation::isEnabled()) {
            $output->writeln('The Composer monitor is not enabled (COMPOSER_REPORT_SERVER_ENABLED).');

            return 1;
        }

        /** @var NotificationService $service */
        $service = Injector::inst()->get(NotificationService::class);

        $stale = $service->queueStale();
        $auditFailing = $service->queueAuditFailing();
        $result = $service->sendPending();
        $removed = $service->cleanUp();

        $output->writeln(sprintf(
            'Queued %d stale and %d audit-failing notifications; sent %d, failed %d; removed %d old rows.',
            $stale,
            $auditFailing,
            $result['sent'],
            $result['failed'],
            $removed,
        ));

        // Non-zero so cron reports a failed delivery
        return $result['failed'] > 0 ? 1 : 0;
    }
}
