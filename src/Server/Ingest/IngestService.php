<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Server\Ingest;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Core\Payload\PayloadBuilder;
use Webium\ComposerReport\Core\Report\Advisory;
use Webium\ComposerReport\Core\Report\AdvisoryDiff;
use Webium\ComposerReport\Core\Report\ReportSummary;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;

/**
 * Stores a validated payload: the report, the project's Last* fields, an
 * outbox row for new advisories and the pruning, in one transaction. No mail
 * is sent here; NotifyTask does that.
 */
class IngestService
{
    use Injectable;

    /**
     * @param array<string, mixed> $payload validated, with invalid sections already set to null
     */
    public function ingest(ComposerReportProject $project, array $payload): ComposerReport
    {
        $report = null;

        DB::get_conn()->withTransaction(function () use ($project, $payload, &$report): void {
            $report = $this->store($project, $payload);
        }, null, false, true);

        if (!$report instanceof ComposerReport) {
            throw new \RuntimeException('The report was not stored');
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(ComposerReportProject $project, array $payload): ComposerReport
    {
        $summary = ReportSummary::fromPayload($payload);
        $environment = is_array($payload['environment'] ?? null) ? $payload['environment'] : [];
        $now = (string) DBDatetime::now()->getValue();

        $report = ComposerReport::create();
        $report->ProjectID = $project->ID;
        $report->ReceivedAt = $now;
        $report->SchemaVersion = (int) $payload['schema_version'];
        $report->PhpVersion = self::shortString($environment['php_version'] ?? null);
        $report->FrameworkVersion = self::shortString($environment['silverstripe_framework'] ?? null);
        $report->OutdatedCount = $summary->outdatedCount();
        $report->AdvisoryCount = $summary->advisoryCount();
        $report->AbandonedCount = $summary->abandonedCount();
        $report->Payload = (new PayloadBuilder())->encode($payload);
        $report->write();

        $project->LastReportAt = $now;
        $project->LastErrorCount = count($summary->errors());

        if ($summary->isOutdatedAvailable()) {
            $project->LastOutdatedCount = $summary->outdatedCount();
        }

        if ($summary->isAuditAvailable()) {
            $new = (new AdvisoryDiff())->newAdvisories($project->lastAdvisoryKeys(), $summary->advisories());

            if ($new !== []) {
                $this->queueNewAdvisories($project, $new, $now);
            }

            $project->LastAdvisoryCount = $summary->advisoryCount();
            $project->LastAbandonedCount = $summary->abandonedCount();
            $project->LastAuditAt = $now;
            $project->LastAdvisoryKeys = json_encode($summary->advisoryKeys(), JSON_THROW_ON_ERROR);
        }

        $project->write();
        $this->prune($project);

        return $report;
    }

    /**
     * @param list<Advisory> $advisories
     */
    private function queueNewAdvisories(ComposerReportProject $project, array $advisories, string $basis): void
    {
        $notification = ComposerReportNotification::create();
        $notification->ProjectID = $project->ID;
        $notification->Type = ComposerReportNotification::TYPE_NEW_ADVISORIES;
        $notification->Basis = substr($basis, 0, 32);
        $notification->Data = json_encode(
            array_map(static fn (Advisory $advisory): array => self::advisoryData($advisory), $advisories),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $notification->write();
    }

    /**
     * @return array<string, string|null>
     */
    public static function advisoryData(Advisory $advisory): array
    {
        return [
            'key'              => $advisory->key(),
            'package'          => $advisory->packageName,
            'displayId'        => $advisory->displayId(),
            'title'            => $advisory->title,
            'link'             => $advisory->safeLink(),
            'affectedVersions' => $advisory->affectedVersions,
            'severity'         => $advisory->severity,
        ];
    }

    /**
     * Keep the newest retention_count reports of the project.
     */
    private function prune(ComposerReportProject $project): void
    {
        $keepIds = ComposerReport::get()
            ->filter('ProjectID', $project->ID)
            ->sort(['ReceivedAt' => 'DESC', 'ID' => 'DESC'])
            ->limit(ServerSettings::singleton()->retentionCount())
            ->column('ID');

        if ($keepIds === []) {
            return;
        }

        // On a plain DataList removeAll() deletes; on the has_many it would only unlink
        ComposerReport::get()->filter('ProjectID', $project->ID)->exclude('ID', $keepIds)->removeAll();
    }

    private static function shortString(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, 50) : null;
    }
}
