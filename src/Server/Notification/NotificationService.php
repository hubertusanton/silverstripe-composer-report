<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Notification;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Core\Security\TextSanitizer;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * Decides what is sent and when; the channels decide how. Works on the
 * ComposerReportNotification outbox, deduplicated per project, type and basis.
 */
class NotificationService
{
    use Injectable;

    private const CLEANUP_AFTER_DAYS = 90;
    private const MAX_ERROR_LENGTH = 255;

    /**
     * @param list<NotificationChannel> $channels
     */
    public function __construct(
        private readonly array $channels = [],
    ) {
    }

    /**
     * Outbox rows for active projects without a report since stale_after_days.
     * One per basis: a new report gives a new basis.
     */
    public function queueStale(): int
    {
        return $this->queue(
            ComposerReportNotification::TYPE_STALE,
            static fn (ComposerReportProject $project): bool => $project->isStale(),
            static fn (ComposerReportProject $project): string => $project->staleBasis(),
        );
    }

    /**
     * Outbox rows for projects that report, but without a successful audit since stale_after_days.
     */
    public function queueAuditFailing(): int
    {
        return $this->queue(
            ComposerReportNotification::TYPE_AUDIT_FAILING,
            static fn (ComposerReportProject $project): bool => $project->isAuditFailing(),
            static fn (ComposerReportProject $project): string => $project->auditBasis(),
        );
    }

    /**
     * Sends pending rows. New-advisory rows of one project are merged and
     * deduplicated, so concurrent ingests do not give duplicate mails.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendPending(): array
    {
        $pending = ComposerReportNotification::get()
            ->filter(['SentAt' => null, 'Attempts:LessThan' => ServerSettings::singleton()->notifyMaxAttempts()])
            ->sort('ID', 'ASC');

        $groups = [];

        foreach ($pending as $notification) {
            $key = $notification->Type === ComposerReportNotification::TYPE_NEW_ADVISORIES
                ? $notification->ProjectID . '-new'
                : (string) $notification->ID;
            $groups[$key][] = $notification;
        }

        $result = ['sent' => 0, 'failed' => 0];

        foreach ($groups as $notifications) {
            $result[$this->sendGroup($notifications) ? 'sent' : 'failed']++;
        }

        return $result;
    }

    /**
     * Removes sent rows older than 90 days.
     */
    public function cleanUp(): int
    {
        $before = DBDatetime::now()->getTimestamp() - self::CLEANUP_AFTER_DAYS * 86400;
        $old = ComposerReportNotification::get()->filter([
            'SentAt:not'      => null,
            'SentAt:LessThan' => date('Y-m-d H:i:s', $before),
        ]);
        $count = $old->count();
        $old->removeAll();

        return $count;
    }

    /**
     * @param callable(ComposerReportProject): bool   $applies
     * @param callable(ComposerReportProject): string $basis
     */
    private function queue(string $type, callable $applies, callable $basis): int
    {
        $created = 0;

        foreach (ComposerReportProject::get()->filter('Active', true) as $project) {
            if (!$applies($project)) {
                continue;
            }

            // Read again just before creating, in case an ingest ran in between
            $project = ComposerReportProject::get()->byID($project->ID);

            if (!$project instanceof ComposerReportProject || !$applies($project)) {
                continue;
            }

            $exists = ComposerReportNotification::get()->filter([
                'ProjectID' => $project->ID,
                'Type'      => $type,
                'Basis'     => $basis($project),
            ])->exists();

            if ($exists) {
                continue;
            }

            $notification = ComposerReportNotification::create();
            $notification->ProjectID = $project->ID;
            $notification->Type = $type;
            $notification->Basis = $basis($project);
            $notification->write();
            $created++;
        }

        return $created;
    }

    /**
     * @param list<ComposerReportNotification> $notifications rows of one project and type
     */
    private function sendGroup(array $notifications): bool
    {
        $first = $notifications[0];
        $project = $first->Project();

        if (!$project->exists() || !$project->Active) {
            // Inactive projects get no notifications; close the rows
            $this->markSent($notifications, 'skipped: project inactive or deleted');

            return true;
        }

        try {
            foreach ($this->channels as $channel) {
                $channel->send($first->Type, $project, $this->mergedAdvisories($notifications));
            }
        } catch (NoRecipientException $exception) {
            $this->logWarning($exception->getMessage());
            $this->markFailed($notifications, $exception->getMessage(), false);

            return false;
        } catch (\Throwable $exception) {
            $this->logWarning(sprintf('sending notification %d failed: %s', $first->ID, $exception->getMessage()));
            $this->markFailed($notifications, $exception->getMessage(), true);

            return false;
        }

        $this->markSent($notifications);

        return true;
    }

    /**
     * @param list<ComposerReportNotification> $notifications
     * @return list<array<string, mixed>>
     */
    private function mergedAdvisories(array $notifications): array
    {
        $merged = [];

        foreach ($notifications as $notification) {
            foreach ($notification->data() as $advisory) {
                if (is_array($advisory) && is_string($advisory['key'] ?? null)) {
                    $merged[$advisory['key']] ??= $advisory;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<ComposerReportNotification> $notifications
     */
    private function markSent(array $notifications, ?string $note = null): void
    {
        foreach ($notifications as $notification) {
            $notification->SentAt = DBDatetime::now()->getValue();
            $notification->LastError = $note;
            $notification->write();
        }
    }

    /**
     * @param list<ComposerReportNotification> $notifications
     */
    private function markFailed(array $notifications, string $error, bool $countAttempt): void
    {
        $error = mb_substr((new TextSanitizer())->redact($error), 0, self::MAX_ERROR_LENGTH);

        foreach ($notifications as $notification) {
            if ($countAttempt) {
                $notification->Attempts = (int) $notification->Attempts + 1;
            }

            $notification->LastError = $error;
            $notification->write();
        }
    }

    private function logWarning(string $message): void
    {
        Injector::inst()->get(LoggerInterface::class)->warning('composer-report: ' . $message);
    }
}
