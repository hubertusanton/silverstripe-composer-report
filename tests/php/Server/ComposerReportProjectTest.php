<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\FieldType\DBDatetime;
use Webium\ComposerReport\Server\Model\ComposerReport;
use Webium\ComposerReport\Server\Model\ComposerReportNotification;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerSettings;

class ComposerReportProjectTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testSlugIsNormalisedBeforeValidation(): void
    {
        $project = ComposerReportProject::create(['Title' => 'Client X']);
        $project->Slug = '  Client-X ';
        $project->write();

        $this->assertSame('client-x', ComposerReportProject::get()->byID($project->ID)->Slug);
    }

    public function testSlugMustBeValid(): void
    {
        $this->expectException(ValidationException::class);

        ComposerReportProject::create(['Title' => 'Bad', 'Slug' => 'client x'])->write();
    }

    public function testSlugIsUnique(): void
    {
        $this->createProject('client-x');

        $result = ComposerReportProject::create(['Title' => 'Copy', 'Slug' => 'client-x'])->validate();

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('already uses this slug', $result->getMessages()[0]['message']);
    }

    public function testNotifyEmailMustBeValid(): void
    {
        $project = ComposerReportProject::create(['Title' => 'X', 'Slug' => 'x', 'NotifyEmail' => 'not an address']);

        $this->assertFalse($project->validate()->isValid());

        $project->NotifyEmail = 'ops@example.com';
        $this->assertTrue($project->validate()->isValid());
    }

    public function testTokenHashIsNotInTheCmsFields(): void
    {
        [$project] = $this->createProject();
        $fields = $project->getCMSFields();

        $this->assertNull($fields->dataFieldByName('TokenHash'));
        $this->assertNull($fields->dataFieldByName('LastAdvisoryKeys'));
        $this->assertSame('Set', $fields->dataFieldByName('TokenStatus')->dataValue());

        foreach ($fields->dataFields() as $field) {
            $this->assertNotSame($project->TokenHash, $field->dataValue(), $field->getName());
        }
    }

    public function testReadonlyFieldsSurviveLoadingTheRecordIntoAForm(): void
    {
        [$project] = $this->createProject();
        $form = Form::create(null, 'ItemEditForm', $project->getCMSFields(), FieldList::create());

        // The CMS form loads the record; a field named like a method of the record would be overwritten
        $form->loadDataFrom($project);

        $this->assertSame('Set', $form->Fields()->dataFieldByName('TokenStatus')->dataValue());
        $this->assertSame('0', $form->Fields()->dataFieldByName('LastAdvisoryCountRO')->getFormattedValue());
    }

    public function testDeletingAProjectDeletesItsReportsAndNotifications(): void
    {
        [$project] = $this->createProject();
        ComposerReport::create(['ProjectID' => $project->ID, 'Payload' => '{}'])->write();
        ComposerReportNotification::create(['ProjectID' => $project->ID, 'Basis' => 'x'])->write();

        $project->delete();

        $this->assertSame(0, ComposerReport::get()->count());
        $this->assertSame(0, ComposerReportNotification::get()->count());
    }

    public function testPermissions(): void
    {
        [$project] = $this->createProject();

        $this->logInWithPermission('CMS_ACCESS_Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin');
        $this->assertTrue($project->canView());
        $this->assertFalse($project->canEdit());
        $this->assertFalse($project->canCreate());

        $this->logInWithPermission(ComposerReportProject::MANAGE_PERMISSION);
        $this->assertTrue($project->canEdit());
        $this->assertTrue($project->canDelete());

        $report = ComposerReport::create(['ProjectID' => $project->ID]);
        $this->assertFalse($report->canEdit());
        $this->assertFalse($report->canCreate());
    }

    public function testStaleAndAuditFailing(): void
    {
        ServerSettings::config()->set('stale_after_days', 3);
        DBDatetime::set_mock_now('2026-11-23 10:00:00');
        [$project] = $this->createProject();

        $this->assertFalse($project->isStale(), 'new project');

        DBDatetime::set_mock_now('2026-11-27 10:00:00');
        $this->assertTrue($project->isStale(), 'never reported, created long ago');
        $this->assertSame(ComposerReportProject::NEVER, $project->staleBasis());

        $project->LastReportAt = '2026-11-27 09:00:00';
        $project->LastAuditAt = '2026-11-20 09:00:00';
        $this->assertFalse($project->isStale());
        $this->assertTrue($project->isAuditFailing());

        $project->LastAuditAt = '2026-11-27 09:00:00';
        $this->assertFalse($project->isAuditFailing());

        $project->LastReportAt = '2026-11-20 09:00:00';
        $project->Active = false;
        $this->assertFalse($project->isStale(), 'inactive projects are never stale');
    }
}
