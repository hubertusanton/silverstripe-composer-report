<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Client;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Client\ClientSettings;
use Webium\ComposerReport\Core\Client\GuzzleReportSender;
use Webium\ComposerReport\Core\Client\ReportClient;
use Webium\ComposerReport\Core\Client\ReportSender;
use Webium\ComposerReport\Core\Composer\ComposerRunner;
use Webium\ComposerReport\Core\Composer\ProcessComposerRunner;
use Webium\ComposerReport\Core\Composer\ProcessEnvironment;
use Webium\ComposerReport\Tests\Core\Fakes\FakeComposerRunner;

class ClientSettingsTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const VARIABLES = [
        'COMPOSER_REPORT_ENDPOINT',
        'COMPOSER_REPORT_PROJECT',
        'COMPOSER_REPORT_TOKEN',
        'COMPOSER_REPORT_COMPOSER_BIN',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedEnv = Environment::getVariables();

        foreach (self::VARIABLES as $name) {
            Environment::setEnv($name, '');
        }
    }

    protected function tearDown(): void
    {
        Environment::setVariables($this->savedEnv);
        FakeRunnerFactory::$runner = null;

        parent::tearDown();
    }

    public function testConfigFromEnvironmentAndDefaults(): void
    {
        Environment::setEnv('COMPOSER_REPORT_ENDPOINT', ' https://monitor.example.com/composer-report/ingest ');
        Environment::setEnv('COMPOSER_REPORT_PROJECT', 'example-project');
        Environment::setEnv('COMPOSER_REPORT_TOKEN', 'crt_abc');

        $config = ClientSettings::create()->createConfig();

        $this->assertSame('https://monitor.example.com/composer-report/ingest', $config->endpoint);
        $this->assertSame('example-project', $config->project);
        $this->assertSame('crt_abc', $config->token?->reveal());
        $this->assertSame(['composer'], $config->composerCommand);
        $this->assertSame(120.0, $config->timeout);
        $this->assertSame(15.0, $config->httpTimeout);
        $this->assertTrue($config->directOnly);
        $this->assertSame(BASE_PATH, $config->basePath);
        $this->assertSame(TEMP_PATH . DIRECTORY_SEPARATOR . 'composer-report-home', $config->composerHomeFallback);
    }

    public function testEmptyVariablesAreMissing(): void
    {
        $config = ClientSettings::create()->createConfig();

        $this->assertNull($config->endpoint);
        $this->assertNull($config->project);
        $this->assertNull($config->token);
    }

    public function testYamlSettings(): void
    {
        ClientSettings::config()->set('composer_bin', ['/opt/php83/bin/php', '/usr/local/bin/composer.phar']);
        ClientSettings::config()->set('timeout', 300);
        ClientSettings::config()->set('http_timeout', 5);
        ClientSettings::config()->set('direct_only', false);

        $config = ClientSettings::create()->createConfig();

        $this->assertSame(['/opt/php83/bin/php', '/usr/local/bin/composer.phar'], $config->composerCommand);
        $this->assertSame(300.0, $config->timeout);
        $this->assertSame(5.0, $config->httpTimeout);
        $this->assertFalse($config->directOnly);
    }

    public function testComposerBinFromEnvironmentWins(): void
    {
        ClientSettings::config()->set('composer_bin', '/usr/bin/composer');
        Environment::setEnv('COMPOSER_REPORT_COMPOSER_BIN', '/opt/php83/bin/php  /usr/local/bin/composer.phar');

        $config = ClientSettings::create()->createConfig();

        $this->assertSame(['/opt/php83/bin/php', '/usr/local/bin/composer.phar'], $config->composerCommand);
    }

    public function testComposerBinAsStringWithArguments(): void
    {
        ClientSettings::config()->set('composer_bin', 'php composer.phar');

        $this->assertSame(['php', 'composer.phar'], ClientSettings::create()->createConfig()->composerCommand);
    }

    public function testDefaultInjectorMapping(): void
    {
        $this->assertInstanceOf(GuzzleReportSender::class, Injector::inst()->get(ReportSender::class));
        $runner = Injector::inst()->createWithArgs(
            ComposerRunner::class,
            [['composer'], BASE_PATH, 1.0, new ProcessEnvironment()],
        );

        $this->assertInstanceOf(ProcessComposerRunner::class, $runner);
    }

    public function testCreateClientPassesTheConfigToTheRunner(): void
    {
        FakeRunnerFactory::$runner = FakeComposerRunner::fromFixtures('clean');
        Injector::inst()->load([ComposerRunner::class => ['factory' => FakeRunnerFactory::class]]);
        ClientSettings::config()->set('composer_bin', ['php', 'composer.phar']);

        $settings = ClientSettings::create();
        $config = $settings->createConfig();
        $client = $settings->createClient($config);

        $this->assertInstanceOf(ReportClient::class, $client);
        $this->assertSame(['php', 'composer.phar'], FakeRunnerFactory::$lastParams[0]);
        $this->assertSame(BASE_PATH, FakeRunnerFactory::$lastParams[1]);
        $this->assertSame(120.0, FakeRunnerFactory::$lastParams[2]);
        $this->assertInstanceOf(ProcessEnvironment::class, FakeRunnerFactory::$lastParams[3]);
    }
}
