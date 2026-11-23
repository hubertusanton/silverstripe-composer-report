<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
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
        $this->channel = new FlakyChannel();
        Injector::inst()->registerService(new NotificationService([$this->channel]), NotificationService::class);
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
        $this->assertFalse(NotifyTask::canRunInBrowser());
        $this->assertSame('tasks:composer-report-notify', NotifyTask::getName());
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
        $project->NotifyEmail = 'ops@example.com';
        $project->write();
        ComposerReportNotification::create([
            'ProjectID' => $project->ID,
            'Type'      => 'new_advisories',
            'Basis'     => 'x',
            'Data'      => '[{"key":"PKSA-1","package":"a/b","displayId":"CVE-1","title":"One"}]',
        ])->write();

        [$exitCode, $output] = $this->runTask();

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $this->channel->delivered);
        $this->assertStringContainsString('sent 1, failed 0', $output);
    }

    public function testFailedDeliveryGivesANonZeroExitCode(): void
    {
        $this->channel = new FlakyChannel(1);
        Injector::inst()->registerService(new NotificationService([$this->channel]), NotificationService::class);
        [$project] = $this->createProject();
        ComposerReportNotification::create([
            'ProjectID' => $project->ID,
            'Type'      => 'new_advisories',
            'Basis'     => 'x',
            'Data'      => '[]',
        ])->write();

        [$exitCode] = $this->runTask();

        $this->assertSame(1, $exitCode);
    }

    /**
     * @return array{int, string}
     */
    private function runTask(): array
    {
        $task = NotifyTask::create();
        $buffer = new BufferedOutput();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, PolyOutput::VERBOSITY_NORMAL, false, $buffer);

        $exitCode = $task->run(new ArrayInput([], new InputDefinition($task->getOptions())), $output);

        return [$exitCode, $buffer->fetch()];
    }
}
