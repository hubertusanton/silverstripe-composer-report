<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Notification;

use Webium\ComposerReport\Server\Model\ComposerReportProject;

/**
 * How a notification is delivered (email now; Slack or a webhook later).
 * NotificationService decides what is sent and when.
 */
interface NotificationChannel
{
    /**
     * @param string                    $type one of the ComposerReportNotification::TYPE_* constants
     * @param list<array<string, mixed>> $advisories new advisories (type new_advisories only)
     * @throws NoRecipientException when the channel has nowhere to deliver to
     * @throws \Throwable on any other failure
     */
    public function send(string $type, ComposerReportProject $project, array $advisories): void;
}
