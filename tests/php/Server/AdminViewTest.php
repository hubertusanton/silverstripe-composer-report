<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Server\Admin\ReportViewModel;
use Webium\ComposerReport\Server\Admin\StatusBadge;
use Webium\ComposerReport\Server\Ingest\IngestService;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;
use Webium\ComposerReport\Tests\Core\Fixture;

class AdminViewTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        DBDatetime::set_mock_now('2026-11-23 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testStatusBadges(): void
    {
        [$project] = $this->createProject();

        $this->assertSame('', (string) StatusBadge::status($project), 'no report yet');

        $project->LastReportAt = '2026-11-23 09:00:00';
        $this->assertStringContainsString('text-bg-success">OK<', (string) StatusBadge::status($project));

        $project->LastAdvisoryCount = 3;
        $project->LastErrorCount = 1;
        $status = (string) StatusBadge::status($project);
        $this->assertStringContainsString('text-bg-danger">Advisories<', $status);
        $this->assertStringContainsString('text-bg-secondary">Error<', $status);
        $this->assertStringNotContainsString('OK', $status);

        $project->Active = false;
        $this->assertStringContainsString('>Inactive<', (string) StatusBadge::status($project));
    }

    public function testStaleBadges(): void
    {
        ServerSettings::config()->set('stale_after_days', 3);
        [$project] = $this->createProject();
        $project->LastReportAt = '2026-11-23 09:00:00';
        $project->LastAuditAt = '2026-11-23 09:00:00';

        $this->assertSame('', (string) StatusBadge::stale($project));

        DBDatetime::set_mock_now('2026-11-28 10:00:00');
        $this->assertStringContainsString('text-bg-warning">Stale<', (string) StatusBadge::stale($project));

        $project->LastReportAt = '2026-11-28 09:00:00';
        $this->assertStringContainsString('>Audit failing<', (string) StatusBadge::stale($project));
    }

    public function testGridColumnsRenderBadgesAsHtml(): void
    {
        [$project] = $this->createProject();
        $project->LastReportAt = '2026-11-23 09:00:00';

        $this->assertStringContainsString('<span class="badge', (string) $project->obj('StatusBadges')->forTemplate());
    }

    public function testReportViewEscapesComposerData(): void
    {
        [$project] = $this->createProject();
        $payload = Fixture::json('payloads/valid-advisory-without-cve.json');
        $payload['project'] = $project->Slug;
        $advisory = &$payload['audit']['advisories']['example/package'][0];
        $advisory['title'] = '<script>alert(1)</script>';
        $advisory['link'] = 'javascript:alert(1)';
        unset($advisory);

        $report = IngestService::singleton()->ingest($project, $payload);
        $html = (string) (new ReportViewModel($report))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('PKSA-0000-0000-0002', $html, 'advisory without a CVE shows its ID');
    }

    public function testReportViewShowsTablesAndLabels(): void
    {
        [$project] = $this->createProject();
        $payload = Fixture::json('payloads/valid-advisories.json');
        $payload['project'] = $project->Slug;

        $report = IngestService::singleton()->ingest($project, $payload);
        $html = (string) (new ReportViewModel($report))->render();

        $this->assertStringContainsString('dompdf/dompdf', $html);
        $this->assertStringContainsString('v2.0.8 → v3.1.6', $html);
        $this->assertStringContainsString('<td>major</td>', $html);
        $this->assertStringContainsString('CVE-2026-59943', $html);
        $this->assertStringContainsString('href="https://github.com/advisories/GHSA-j8qw-6jw8-r297"', $html);
        $this->assertStringContainsString('psr/http-factory', $html, 'abandoned with replacement');
    }

    public function testFailedAuditIsExplained(): void
    {
        [$project] = $this->createProject();
        $payload = Fixture::json('payloads/valid-partial-audit-failed.json');
        $payload['project'] = $project->Slug;

        $report = IngestService::singleton()->ingest($project, $payload);
        $html = (string) (new ReportViewModel($report))->render();

        $this->assertStringContainsString('The audit failed for this report', $html);
        $this->assertStringContainsString('may come from the Composer cache', $html);
    }

    public function testProjectAndReportCmsFields(): void
    {
        [$project] = $this->createProject();
        $payload = Fixture::json('payloads/valid-clean.json');
        $payload['project'] = $project->Slug;
        $report = IngestService::singleton()->ingest($project, $payload);

        $fields = $project->getCMSFields();
        $this->assertNotNull($fields->fieldByName('Root.LatestReport.LatestReportView'));
        $this->assertNotNull($fields->dataFieldByName('Reports'));

        $reportFields = ComposerReport::get()->byID($report->ID)->getCMSFields();
        $this->assertNotNull($reportFields->fieldByName('Root.Main.ReportView'));
        $this->assertNotNull($reportFields->fieldByName('Root.Main.RawPayload'));

        $new = ComposerReportProject::create();
        $this->assertNull($new->getCMSFields()->dataFieldByName('Reports'), 'no history tab before the first save');
    }
}
