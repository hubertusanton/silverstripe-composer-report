<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Permission;
use Webium\ComposerReport\Server\Admin\ComposerMonitorAdmin;
use Webium\ComposerReport\Server\Control\IngestController;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerActivation;

/**
 * The server parts must be absent on client installs. The checks of the
 * actual configuration only run when COMPOSER_REPORT_SERVER_ENABLED is not
 * set in this environment; the YAML itself is checked statically.
 */
class ActivationTest extends FunctionalTest
{
    use ServerTestState;

    protected $usesDatabase = true;

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function testServerEnabledYamlIsGuardedByAStrictEnvCheck(): void
    {
        [$header, $body] = self::serverEnabledYaml();

        $this->assertSame('composer-report-server-enabled', $header['Name']);
        $this->assertSame(['COMPOSER_REPORT_SERVER_ENABLED' => true], $header['Only']['envvarset']);
        $this->assertSame(
            ['composer-report/ingest' => IngestController::class],
            $body['SilverStripe\Control\Director']['rules'],
        );
        $this->assertFalse($body[ComposerMonitorAdmin::class]['ignore_menuitem']);
        $this->assertSame(
            'CMS_ACCESS_' . ComposerMonitorAdmin::class,
            $body[ComposerMonitorAdmin::class]['required_permission_codes'],
        );
        $this->assertSame(['#^composer-report/ingest$#' => false], self::basicAuthPatterns());
    }

    public function testClassDefaultsAreSafeForClients(): void
    {
        // Without a url_segment every CMS request on a client would crash
        $this->assertSame('composer-monitor', $this->staticValue('url_segment'));
        $this->assertTrue($this->staticValue('ignore_menuitem'));
        $this->assertFalse($this->staticValue('required_permission_codes'));
    }

    public function testRuntimeGuard(): void
    {
        $cases = [[true, true], ['true', true], ['1', true], [false, false], ['false', false], ['', false]];

        foreach ($cases as [$value, $expected]) {
            $this->enableServer(false);
            Environment::setEnv(ServerActivation::ENV_VARIABLE, $value);

            $this->assertSame($expected, ServerActivation::isEnabled(), var_export($value, true));
        }
    }

    public function testPermissionOnlyWhenEnabled(): void
    {
        $this->enableServer(false);
        $this->assertSame([], ComposerReportProject::singleton()->providePermissions());

        $this->enableServer();
        $permissions = ComposerReportProject::singleton()->providePermissions();
        $this->assertArrayHasKey(ComposerReportProject::MANAGE_PERMISSION, $permissions);
    }

    public function testClientInstallHasNoRouteMenuOrPermission(): void
    {
        if (Environment::getEnv(ServerActivation::ENV_VARIABLE) !== false) {
            $this->markTestSkipped(
                'COMPOSER_REPORT_SERVER_ENABLED is set here; '
                . 'the absence of the route and menu can only be checked on a client install.',
            );
        }

        $this->assertArrayNotHasKey('composer-report/ingest', Director::config()->get('rules'));
        $this->assertTrue(ComposerMonitorAdmin::config()->get('ignore_menuitem'));

        $codes = array_keys(Permission::get_codes(false));
        $this->assertNotContains('CMS_ACCESS_' . ComposerMonitorAdmin::class, $codes);
        $this->assertNotContains(ComposerReportProject::MANAGE_PERMISSION, $codes);
    }

    public function testCmsStillWorksWithTheServerOff(): void
    {
        $this->enableServer(false);
        $this->logInWithPermission('ADMIN');

        $this->assertSame(200, $this->get('admin/security')->getStatusCode());

        // Without access LeftAndMain redirects a logged-in member to a section they may see
        $this->autoFollowRedirection = false;
        $response = $this->get('admin/composer-monitor');

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('composer-monitor', (string) $response->getHeader('Location'));
    }

    private function staticValue(string $name): mixed
    {
        $property = new \ReflectionProperty(ComposerMonitorAdmin::class, $name);

        return $property->getDefaultValue();
    }
}
