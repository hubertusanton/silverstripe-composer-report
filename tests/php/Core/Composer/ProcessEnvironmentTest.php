<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Composer;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Composer\ProcessEnvironment;

class ProcessEnvironmentTest extends SapphireTest
{
    protected $usesDatabase = false;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/composer-report-env-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testFixedOverrides(): void
    {
        $overrides = (new ProcessEnvironment())->overrides(['HOME' => '/home/web']);

        $this->assertSame('0', $overrides['SHELL_VERBOSITY']);
        $this->assertSame('1', $overrides['COMPOSER_NO_INTERACTION']);
        $this->assertFalse($overrides['COMPOSER_REPORT_TOKEN']);
        $this->assertFalse($overrides['SS_DATABASE_PASSWORD']);
        $this->assertFalse($overrides['SS_DEFAULT_ADMIN_PASSWORD']);
        $this->assertArrayNotHasKey('COMPOSER_HOME', $overrides);
    }

    public function testComposerRootVersion(): void
    {
        $home = ['HOME' => '/h'];

        $this->assertSame('dev-main', (new ProcessEnvironment())->overrides($home)['COMPOSER_ROOT_VERSION']);
        $this->assertSame('1.x-dev', (new ProcessEnvironment('1.x-dev'))->overrides($home)['COMPOSER_ROOT_VERSION']);
        $this->assertArrayNotHasKey('COMPOSER_ROOT_VERSION', (new ProcessEnvironment(null))->overrides($home));

        $inherited = $home + ['COMPOSER_ROOT_VERSION' => '3.0.0'];
        $this->assertArrayNotHasKey('COMPOSER_ROOT_VERSION', (new ProcessEnvironment())->overrides($inherited));
    }

    public function testComposerHomeOnlyWithoutHomeAndComposerHome(): void
    {
        $fallback = $this->tempDir . '/home';
        $environment = new ProcessEnvironment('dev-main', $fallback);

        $this->assertArrayNotHasKey('COMPOSER_HOME', $environment->overrides(['HOME' => '/home/web']));
        $this->assertArrayNotHasKey('COMPOSER_HOME', $environment->overrides(['COMPOSER_HOME' => '/composer']));

        $warnings = [];
        $overrides = $environment->overrides(['HOME' => ''], $warnings);

        $this->assertSame($fallback, $overrides['COMPOSER_HOME']);
        $this->assertSame([], $warnings);
    }

    public function testFallbackDirectoryIsCreatedWith0700(): void
    {
        $fallback = $this->tempDir . '/nested/home';

        (new ProcessEnvironment('dev-main', $fallback))->overrides([]);

        $this->assertDirectoryExists($fallback);
        $this->assertSame(0700, fileperms($fallback) & 0777);
    }

    public function testFallbackDirectoryWithWideRightsIsRefused(): void
    {
        $fallback = $this->tempDir . '/wide';
        mkdir($fallback);
        chmod($fallback, 0755);

        $warnings = [];
        $overrides = (new ProcessEnvironment('dev-main', $fallback))->overrides([], $warnings);

        $this->assertArrayNotHasKey('COMPOSER_HOME', $overrides);
        $this->assertSame(['COMPOSER_HOME not set: the fallback directory is accessible to other users'], $warnings);
    }

    public function testSymlinkedFallbackDirectoryIsRefused(): void
    {
        $target = $this->tempDir . '/target';
        mkdir($target, 0700);
        symlink($target, $this->tempDir . '/link');

        $warnings = [];
        $overrides = (new ProcessEnvironment('dev-main', $this->tempDir . '/link'))->overrides([], $warnings);

        $this->assertArrayNotHasKey('COMPOSER_HOME', $overrides);
        $this->assertSame(['COMPOSER_HOME not set: the fallback directory is a symlink'], $warnings);
    }

    public function testNoFallbackGivesAWarning(): void
    {
        $warnings = [];
        $overrides = (new ProcessEnvironment())->overrides([], $warnings);

        $this->assertArrayNotHasKey('COMPOSER_HOME', $overrides);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no fallback directory', $warnings[0]);
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            unlink($directory);
            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDirectory($directory . '/' . $entry);
            }
        }

        rmdir($directory);
    }
}
