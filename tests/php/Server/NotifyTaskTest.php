<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Notification\NotificationService;
use Webium\ComposerReport\Server\Task\NotifyTask;

class NotifyTaskTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    private FlakyChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        Email::config()->set('admin_email', 'monitor@example.com');
        $this->useChannel(new FlakyChannel());
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testDisabledOnClientsByDefault(): void
    {
        $defaults = new \ReflectionProperty(NotifyTask::class, 'is_enabled');

        $this->assertFalse($defaults->getDefaultValue());
        $this->assertTrue(NotifyTask::singleton()->canView(), 'CLI only');
    }

    public function testRefusesWhenTheServerIsOff(): void
    {
        $this->enableServer(false);

        [$exitCode, $output] = $this->runTask();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not enabled', $output);
    }

    public function testSendsPendingNotifications(): void
    {
        [$project] = $this->createProject();
        $this->queueNotification($project->ID, '[{"key":"PKSA-1","package":"a/b","displayId":"CVE-1","title":"One"}]');

        [$exitCode, $output] = $this->runTask();

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $this->channel->delivered);
        $this->assertStringContainsString('sent 1, failed 0', $output);
    }

    public function testFailedDeliveryEndsWithANonZeroExitCode(): void
    {
        $this->useChannel(new FlakyChannel(1));
        [$project] = $this->createProject();
        $this->queueNotification($project->ID, '[]');

        $task = TestNotifyTask::create();
        ob_start();
        $task->run(null);
        ob_end_clean();

        $this->assertSame(1, $task->terminatedWith);
    }

    /**
     * @return array{int, string}
     */
    private function runTask(): array
    {
        ob_start();
        $exitCode = NotifyTask::create()->runWithExitCode();

        return [$exitCode, (string) ob_get_clean()];
    }

    private function useChannel(FlakyChannel $channel): void
    {
        $this->channel = $channel;
        Injector::inst()->registerService(new NotificationService([$channel]), NotificationService::class);
    }

    private function queueNotification(int $projectId, string $data): void
    {
        ComposerReportNotification::create([
            'ProjectID' => $projectId,
            'Type'      => ComposerReportNotification::TYPE_NEW_ADVISORIES,
            'Basis'     => 'x',
            'Data'      => $data,
        ])->write();
    }
}
