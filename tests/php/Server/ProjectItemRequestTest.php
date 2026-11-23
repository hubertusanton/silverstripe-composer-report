<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\SudoMode\SudoModeServiceInterface;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;
use Webium\ComposerReport\Server\Admin\ProjectItemRequest;
use Webium\ComposerReport\Server\Model\ComposerReportProject;

/**
 * Works on a ProjectItemRequest directly: AdminRootController caches its
 * rules statically, so an admin URL is not reliable in tests.
 */
class ProjectItemRequestTest extends SapphireTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    private ComposerReportProject $project;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableServer();
        [$this->project] = $this->createProject();
        $this->session = new Session([]);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testGenerateTokenShowsItOnceAndStoresOnlyTheHash(): void
    {
        $this->logInAsManager();
        $this->activateSudoMode();
        $oldHash = $this->project->TokenHash;
        $itemRequest = $this->itemRequest();

        $itemRequest->doGenerateToken([], $itemRequest->ItemEditForm());

        $project = ComposerReportProject::get()->byID($this->project->ID);
        $this->assertNotSame($oldHash, $project->TokenHash);

        $field = $itemRequest->ItemEditForm()->Fields()->fieldByName('FreshToken');
        $this->assertInstanceOf(LiteralField::class, $field);
        $this->assertSame(1, preg_match('/crt_[0-9a-f]{64}/', (string) $field->getContent(), $matches));

        $token = $matches[0];
        $this->assertSame(hash('sha256', $token), $project->TokenHash);
        $this->assertNotSame($token, $project->TokenHash);
        $this->assertStringNotContainsString($token, serialize($this->session->getAll()));
    }

    public function testFreshItemRequestDoesNotShowAToken(): void
    {
        $this->logInAsManager();

        $this->assertNull($this->itemRequest()->ItemEditForm()->Fields()->fieldByName('FreshToken'));
    }

    public function testSudoModeIsRequired(): void
    {
        $this->logInAsManager();
        $itemRequest = $this->itemRequest();
        $oldHash = $this->project->TokenHash;

        try {
            $itemRequest->doGenerateToken([], $itemRequest->ItemEditForm());
            $this->fail('expected a 403');
        } catch (HTTPResponse_Exception $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }

        $this->assertSame($oldHash, ComposerReportProject::get()->byID($this->project->ID)->TokenHash);
    }

    public function testManagePermissionIsRequired(): void
    {
        $this->logInWithPermission('CMS_ACCESS_' . ComposerMonitorAdmin::class);
        $this->activateSudoMode();
        $itemRequest = $this->itemRequest();

        $this->assertNull($itemRequest->ItemEditForm()->Actions()->dataFieldByName('action_doGenerateToken'));

        $this->expectException(HTTPResponse_Exception::class);
        $itemRequest->doGenerateToken([], $itemRequest->ItemEditForm());
    }

    public function testActionIsOfferedToManagers(): void
    {
        $this->logInAsManager();
        $this->activateSudoMode();

        $action = $this->itemRequest()->ItemEditForm()->Actions()->dataFieldByName('action_doGenerateToken');

        $this->assertNotNull($action);
        $this->assertStringContainsString('confirm(', (string) $action->getAttribute('onclick'));
    }

    public function testOverriddenFormActionIsStillAllowed(): void
    {
        $this->logInAsManager();
        $itemRequest = $this->itemRequest();

        // Without this every submit of the project form (Save, Generate new token) gives a 403
        $this->assertTrue($itemRequest->checkAccessAction('ItemEditForm'));
        $this->assertTrue($itemRequest->checkAccessAction('edit'));
    }

    public function testNoImportForm(): void
    {
        $this->assertFalse(ComposerMonitorAdmin::singleton()->showImportForm);
    }

    private function logInAsManager(): void
    {
        $this->logInWithPermission([
            ComposerReportProject::MANAGE_PERMISSION,
            'CMS_ACCESS_' . ComposerMonitorAdmin::class,
        ]);
    }

    private function activateSudoMode(): void
    {
        Injector::inst()->get(SudoModeServiceInterface::class)->activate($this->session);
    }

    private function itemRequest(): ProjectItemRequest
    {
        $request = new HTTPRequest('POST', 'admin/composer-monitor/edit');
        $request->setSession($this->session);

        $admin = TestAdminController::create();
        $admin->setRequest($request);

        $config = GridFieldConfig_RecordEditor::create();
        $gridField = GridField::create('Projects', 'Projects', ComposerReportProject::get(), $config);
        Form::create($admin, 'EditForm', FieldList::create($gridField), FieldList::create());

        /** @var GridFieldDetailForm $component */
        $component = $config->getComponentByType(GridFieldDetailForm::class);

        return ProjectItemRequest::create($gridField, $component, $this->project, $admin, 'DetailForm');
    }
}
