<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Server\Ingest\IngestService;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;
use Webium\ComposerReport\Tests\Core\Fixture;

class IngestServiceTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    private ComposerReportProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        [$this->project] = $this->createProject();
        DBDatetime::set_mock_now('2026-11-23 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testFirstReportTreatsAllAdvisoriesAsNew(): void
    {
        $this->ingest('valid-advisories.json');

        $notifications = ComposerReportNotification::get()->filter('ProjectID', $this->project->ID);

        $this->assertSame(1, $notifications->count());
        $notification = $notifications->first();
        $this->assertSame(ComposerReportNotification::TYPE_NEW_ADVISORIES, $notification->Type);
        $this->assertCount(11, $notification->data());
        $this->assertSame(
            ['key', 'package', 'displayId', 'title', 'link', 'affectedVersions', 'severity'],
            array_keys($notification->data()[0]),
        );
        $this->assertNull($notification->SentAt);
    }

    public function testIdenticalSecondReportGivesNoNotification(): void
    {
        $this->ingest('valid-advisories.json');
        DBDatetime::set_mock_now('2026-11-23 11:00:00');
        $this->ingest('valid-advisories.json');

        $this->assertSame(1, ComposerReportNotification::get()->count());
        $this->assertSame(2, ComposerReport::get()->count());
    }

    public function testOneNewAdvisoryGivesANotificationWithOnlyThatOne(): void
    {
        $payload = Fixture::json('payloads/valid-advisories.json');
        $first = $payload;
        array_pop($first['audit']['advisories']['guzzlehttp/psr7']);

        $this->ingestPayload($first);
        $this->ingestPayload($payload);

        $latest = ComposerReportNotification::get()->sort('ID', 'DESC')->first();
        $this->assertSame(2, ComposerReportNotification::get()->count());
        $this->assertCount(1, $latest->data());
        $this->assertSame('PKSA-hn62-zkx4-1y5q', $latest->data()[0]['key']);
    }

    public function testLastFieldsOfACompleteReport(): void
    {
        $this->ingest('valid-advisories.json');
        $project = $this->reload();

        $this->assertSame('2026-11-23 10:00:00', $project->LastReportAt);
        $this->assertSame('2026-11-23 10:00:00', $project->LastAuditAt);
        $this->assertSame(2, (int) $project->LastOutdatedCount);
        $this->assertSame(11, (int) $project->LastAdvisoryCount);
        $this->assertSame(1, (int) $project->LastAbandonedCount);
        $this->assertSame(0, (int) $project->LastErrorCount);
        $this->assertCount(11, $project->lastAdvisoryKeys());
    }

    public function testFailedAuditDoesNotOverwriteAuditFields(): void
    {
        $this->ingest('valid-advisories.json');
        DBDatetime::set_mock_now('2026-11-24 10:00:00');
        $this->ingest('valid-partial-audit-failed.json');
        $project = $this->reload();

        $this->assertSame('2026-11-24 10:00:00', $project->LastReportAt);
        $this->assertSame('2026-11-23 10:00:00', $project->LastAuditAt, 'LastAuditAt only moves on a successful audit');
        $this->assertSame(11, (int) $project->LastAdvisoryCount);
        $this->assertCount(11, $project->lastAdvisoryKeys());
        $this->assertSame(2, (int) $project->LastErrorCount);
    }

    public function testFailedOutdatedDoesNotOverwriteOutdatedCount(): void
    {
        $this->ingest('valid-advisories.json');

        $payload = Fixture::json('payloads/valid-advisories.json');
        $payload['outdated'] = null;
        $payload['errors'] = ['outdated failed'];
        $this->ingestPayload($payload);

        $this->assertSame(2, (int) $this->reload()->LastOutdatedCount);
    }

    public function testPruneKeepsExactlyTheRetentionCount(): void
    {
        ServerSettings::config()->set('rate_limit_per_hour', 1);
        ServerSettings::config()->set('retention_count', 3);

        // All with the same ReceivedAt: the ID decides
        for ($i = 0; $i < 5; $i++) {
            $reports[] = $this->ingest('valid-clean.json');
        }

        $remaining = ComposerReport::get()->filter('ProjectID', $this->project->ID)->column('ID');
        sort($remaining);

        $this->assertSame([$reports[2]->ID, $reports[3]->ID, $reports[4]->ID], $remaining);
    }

    public function testPruneOnlyTouchesTheProject(): void
    {
        ServerSettings::config()->set('rate_limit_per_hour', 1);
        ServerSettings::config()->set('retention_count', 1);
        [$other] = $this->createProject('other-project');

        $payload = Fixture::json('payloads/valid-clean.json');
        $payload['project'] = 'other-project';
        IngestService::singleton()->ingest($other, $payload);

        $this->ingest('valid-clean.json');
        $this->ingest('valid-clean.json');

        $this->assertSame(1, ComposerReport::get()->filter('ProjectID', $other->ID)->count());
        $this->assertSame(1, ComposerReport::get()->filter('ProjectID', $this->project->ID)->count());
    }

    public function testRetentionIsNeverBelowTheRateLimit(): void
    {
        ServerSettings::config()->set('rate_limit_per_hour', 10);
        ServerSettings::config()->set('retention_count', 2);

        $this->assertSame(10, ServerSettings::singleton()->retentionCount());
    }

    private function ingest(string $fixture): ComposerReport
    {
        return $this->ingestPayload(Fixture::json('payloads/' . $fixture));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ingestPayload(array $payload): ComposerReport
    {
        $payload['project'] = $this->project->Slug;

        return IngestService::singleton()->ingest($this->reload(), $payload);
    }

    private function reload(): ComposerReportProject
    {
        return ComposerReportProject::get()->byID($this->project->ID);
    }
}
