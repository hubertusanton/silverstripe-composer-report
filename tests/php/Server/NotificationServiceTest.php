<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Server\Ingest\IngestService;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\Notification\EmailChannel;
use Webium\ComposerReport\Server\Notification\NotificationService;
use Webium\ComposerReport\Server\ServerSettings;
use Webium\ComposerReport\Tests\Core\Fixture;

class NotificationServiceTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_EMAIL', '');
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_FROM', '');
        Email::config()->set('admin_email', 'monitor@example.com');
        ServerSettings::config()->set('notify_email', 'ops@example.com');
        ServerSettings::config()->set('stale_after_days', 3);
        DBDatetime::set_mock_now('2026-11-23 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testNewAdvisoriesAreMailed(): void
    {
        $project = $this->projectWithReport('valid-advisories.json');

        $result = $this->emailService()->sendPending();

        $this->assertSame(['sent' => 1, 'failed' => 0], $result);
        $subject = '/11 new security advisories for Example project/';
        $this->assertEmailSent('ops@example.com', 'monitor@example.com', $subject);

        $email = $this->findEmail('ops@example.com', null, $subject);
        $this->assertStringContainsString('CVE-2026-59943', (string) $email['HtmlContent']);
        $this->assertStringContainsString('admin/composer-monitor', (string) $email['HtmlContent']);
        $this->assertStringContainsString('CVE-2026-59943', (string) $email['PlainContent']);
        $this->assertNotNull(ComposerReportNotification::get()->filter('ProjectID', $project->ID)->first()->SentAt);
    }

    public function testPendingNewAdvisoriesOfAProjectAreMergedIntoOneMail(): void
    {
        [$project] = $this->createProject();
        $advisory = ['key' => 'PKSA-1', 'package' => 'a/b', 'displayId' => 'CVE-1', 'title' => 'One'];

        foreach ([[$advisory], [$advisory, ['key' => 'PKSA-2'] + $advisory]] as $data) {
            ComposerReportNotification::create([
                'ProjectID' => $project->ID,
                'Type'      => ComposerReportNotification::TYPE_NEW_ADVISORIES,
                'Basis'     => 'x',
                'Data'      => json_encode($data),
            ])->write();
        }

        $channel = new FlakyChannel();
        $result = (new NotificationService([$channel]))->sendPending();

        $this->assertSame(['sent' => 1, 'failed' => 0], $result);
        $this->assertCount(1, $channel->delivered);
        $this->assertSame(['PKSA-1', 'PKSA-2'], array_column($channel->delivered[0]['advisories'], 'key'));
        $this->assertSame(0, ComposerReportNotification::get()->filter('SentAt', null)->count());
    }

    public function testFailedDeliveryIsRetried(): void
    {
        $this->projectWithReport('valid-advisories.json');
        $channel = new FlakyChannel(1);
        $service = new NotificationService([$channel]);

        $this->assertSame(['sent' => 0, 'failed' => 1], $service->sendPending());

        $notification = ComposerReportNotification::get()->first();
        $this->assertSame(1, (int) $notification->Attempts);
        $this->assertStringContainsString('SMTP down', (string) $notification->LastError);
        $this->assertStringNotContainsString('crt_0123456789abcdef', (string) $notification->LastError);
        $this->assertNull($notification->SentAt);

        $this->assertSame(['sent' => 1, 'failed' => 0], $service->sendPending());
        $this->assertCount(1, $channel->delivered);
    }

    public function testDeliveryStopsAfterTheMaximumAttempts(): void
    {
        ServerSettings::config()->set('notify_max_attempts', 2);
        $this->projectWithReport('valid-advisories.json');
        $service = new NotificationService([new FlakyChannel(10)]);

        $service->sendPending();
        $service->sendPending();

        $this->assertSame(['sent' => 0, 'failed' => 0], $service->sendPending());
    }

    public function testStaleOncePerBasisAndAgainAfterANewReport(): void
    {
        $project = $this->projectWithReport('valid-clean.json');
        $service = $this->emailService();
        $service->sendPending();

        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        $this->assertSame(1, $service->queueStale());
        $this->assertSame(0, $service->queueStale(), 'same basis, no second row');
        $service->sendPending();
        $this->assertEmailSent('ops@example.com', null, '/No report from Example project/');

        // A new report and a new stale period give a new notification
        $this->ingest($project, 'valid-clean.json');
        $this->assertSame(0, $service->queueStale());
        DBDatetime::set_mock_now('2026-12-01 10:00:00');
        $this->assertSame(1, $service->queueStale());
    }

    public function testProjectThatNeverReportedBecomesStale(): void
    {
        [$project] = $this->createProject();

        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        $this->assertSame(1, $this->emailService()->queueStale());

        $notification = ComposerReportNotification::get()->filter('ProjectID', $project->ID)->first();
        $this->assertSame(ComposerReportProject::NEVER, $notification->Basis);
    }

    public function testAuditFailing(): void
    {
        $project = $this->projectWithReport('valid-clean.json');
        $service = $this->emailService();
        $service->sendPending();

        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        $this->ingest($project, 'valid-partial-audit-failed.json');

        $this->assertSame(0, $service->queueStale());
        $this->assertSame(1, $service->queueAuditFailing());
        $this->assertSame(0, $service->queueAuditFailing());
        $service->sendPending();
        $this->assertEmailSent('ops@example.com', null, '/The audit of Example project keeps failing/');
    }

    public function testInactiveProjectsGetNoNotifications(): void
    {
        $project = $this->projectWithReport('valid-advisories.json');
        $project->Active = false;
        $project->write();

        DBDatetime::set_mock_now('2026-11-30 10:00:00');
        $service = $this->emailService();

        $this->assertSame(0, $service->queueStale());
        $service->sendPending();
        $this->assertNull($this->findEmail('ops@example.com'));
    }

    public function testRecipientOrder(): void
    {
        $project = $this->projectWithReport('valid-advisories.json');
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_EMAIL', 'env@example.com');
        $project->NotifyEmail = 'project@example.com';
        $project->write();

        $this->emailService()->sendPending();

        $this->assertEmailSent('project@example.com');
        $this->assertNull($this->findEmail('env@example.com'));
        $this->assertNull($this->findEmail('ops@example.com'));
    }

    public function testEnvironmentRecipientWinsOverYaml(): void
    {
        $this->projectWithReport('valid-advisories.json');
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_EMAIL', 'env@example.com');
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_FROM', 'sender@example.com');

        $this->emailService()->sendPending();

        $this->assertEmailSent('env@example.com', 'sender@example.com');
    }

    public function testInvalidAddressIsSkipped(): void
    {
        $this->projectWithReport('valid-advisories.json');
        Environment::setEnv('COMPOSER_REPORT_NOTIFY_EMAIL', 'not an address');

        $this->emailService()->sendPending();

        $this->assertEmailSent('ops@example.com');
    }

    public function testWithoutRecipientTheNotificationStaysPending(): void
    {
        $this->projectWithReport('valid-advisories.json');
        ServerSettings::config()->set('notify_email', '');

        $this->assertSame(['sent' => 0, 'failed' => 1], $this->emailService()->sendPending());

        $notification = ComposerReportNotification::get()->first();
        $this->assertNull($notification->SentAt);
        $this->assertSame(0, (int) $notification->Attempts, 'a config problem does not use up attempts');
        $this->assertStringContainsString('No valid recipient', (string) $notification->LastError);
    }

    public function testOldSentRowsAreRemoved(): void
    {
        $this->projectWithReport('valid-advisories.json');
        $service = $this->emailService();
        $service->sendPending();

        DBDatetime::set_mock_now('2027-01-30 10:00:00');
        $this->assertSame(0, $service->cleanUp());

        DBDatetime::set_mock_now('2027-02-22 10:00:00');
        $this->assertSame(1, $service->cleanUp());
        $this->assertSame(0, ComposerReportNotification::get()->count());
    }

    private function emailService(): NotificationService
    {
        return new NotificationService([EmailChannel::create()]);
    }

    private function projectWithReport(string $fixture): ComposerReportProject
    {
        [$project] = $this->createProject();
        $this->ingest($project, $fixture);

        return ComposerReportProject::get()->byID($project->ID);
    }

    private function ingest(ComposerReportProject $project, string $fixture): void
    {
        $payload = Fixture::json('payloads/' . $fixture);
        $payload['project'] = $project->Slug;

        IngestService::singleton()->ingest(ComposerReportProject::get()->byID($project->ID), $payload);
    }
}
