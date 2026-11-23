<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Client;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Webium\ComposerReport\Core\Client\ClientConfig;
use Webium\ComposerReport\Core\Client\ReportClient;
use Webium\ComposerReport\Core\Client\ReportSender;
use Webium\ComposerReport\Core\Composer\ComposerOutputParser;
use Webium\ComposerReport\Core\Composer\ComposerRunner;
use Webium\ComposerReport\Core\Composer\ProcessEnvironment;
use Webium\ComposerReport\Core\Payload\PayloadBuilder;
use Webium\ComposerReport\Core\Payload\PayloadValidator;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Core\Security\TextSanitizer;

/**
 * Turns .env and YAML into a ClientConfig and a ReportClient. The runner and
 * sender come from the Injector, so projects and tests can replace them.
 */
class ClientSettings
{
    use Configurable;
    use Injectable;

    /**
     * Composer command: a string, or a list such as ['/opt/php83/bin/php', '/usr/local/bin/composer.phar'].
     * The env variable COMPOSER_REPORT_COMPOSER_BIN overrides it (split on whitespace).
     *
     * @config
     * @var string|list<string>
     */
    private static string|array $composer_bin = 'composer';

    /**
     * Timeout per Composer command, in seconds.
     *
     * @config
     */
    private static int|float $timeout = 120;

    /**
     * Timeout of the request to the monitor, in seconds.
     *
     * @config
     */
    private static int|float $http_timeout = 15;

    /**
     * Pass --direct to composer outdated.
     *
     * @config
     */
    private static bool $direct_only = true;

    /**
     * COMPOSER_ROOT_VERSION for the Composer process when the environment has none; null leaves it unset.
     *
     * @config
     */
    private static ?string $composer_root_version = 'dev-main';

    public function createConfig(): ClientConfig
    {
        $token = self::env('COMPOSER_REPORT_TOKEN');

        return new ClientConfig(
            self::env('COMPOSER_REPORT_ENDPOINT'),
            self::env('COMPOSER_REPORT_PROJECT'),
            $token === null ? null : Secret::fromString($token),
            $this->composerCommand(),
            (float) static::config()->get('timeout'),
            (float) static::config()->get('http_timeout'),
            (bool) static::config()->get('direct_only'),
            BASE_PATH,
            TEMP_PATH . DIRECTORY_SEPARATOR . 'composer-report-home',
        );
    }

    public function createClient(ClientConfig $config): ReportClient
    {
        $sanitizer = new TextSanitizer($config->basePath);
        $environment = new ProcessEnvironment(
            static::config()->get('composer_root_version'),
            $config->composerHomeFallback,
        );

        /** @var ComposerRunner $runner */
        $runner = Injector::inst()->createWithArgs(ComposerRunner::class, [
            $config->composerCommand,
            $config->basePath,
            $config->timeout,
            $environment,
        ]);
        /** @var ReportSender $sender */
        $sender = Injector::inst()->get(ReportSender::class);

        return new ReportClient(
            $runner,
            $sender,
            new PayloadBuilder(),
            new PayloadValidator(),
            new ComposerOutputParser($sanitizer),
            $sanitizer,
        );
    }

    /**
     * @return list<string>
     */
    private function composerCommand(): array
    {
        $fromEnv = self::env('COMPOSER_REPORT_COMPOSER_BIN');
        $command = $fromEnv ?? static::config()->get('composer_bin');

        if (is_string($command)) {
            $command = preg_split('/\s+/', trim($command)) ?: [];
        }

        return array_values(array_filter(
            array_map('strval', (array) $command),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * Trimmed value, or null when unset or empty.
     */
    private static function env(string $name): ?string
    {
        $value = Environment::getEnv($name);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
