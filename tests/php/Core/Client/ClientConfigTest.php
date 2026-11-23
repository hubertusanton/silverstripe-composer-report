<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Client;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Client\ClientConfig;
use Webium\ComposerReport\Core\Security\Secret;

class ClientConfigTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const NOT_HTTPS = 'COMPOSER_REPORT_ENDPOINT must be an https:// URL.';

    public function testCompleteConfigIsValid(): void
    {
        $this->assertSame([], self::config()->validate(false));
        $this->assertSame([], self::config()->validate(true));
    }

    public function testEndpointMustBeHttps(): void
    {
        $urls = ['http://monitor.example.com/composer-report/ingest', 'https:///no-host', 'monitor.example.com'];

        foreach ($urls as $url) {
            $this->assertSame([self::NOT_HTTPS], self::config(endpoint: $url)->validate(false), $url);
        }

        $this->assertSame([], self::config(endpoint: 'HTTPS://monitor.example.com/x')->validate(false));
    }

    public function testMissingSettingsWhenSending(): void
    {
        $config = self::config(endpoint: null, project: null, token: null);

        $this->assertSame(
            [
                'COMPOSER_REPORT_PROJECT is not set.',
                'COMPOSER_REPORT_ENDPOINT is not set.',
                'COMPOSER_REPORT_TOKEN is not set.',
            ],
            $config->validate(false),
        );
        $this->assertSame(['COMPOSER_REPORT_TOKEN is not set.'], self::config(token: '')->validate(false));
    }

    public function testDryRunOnlyNeedsAProject(): void
    {
        $this->assertSame([], self::config(endpoint: null, token: null)->validate(true));
        $this->assertSame([], self::config(endpoint: 'http://insecure.example.com')->validate(true));

        $withoutProject = self::config(project: '', endpoint: null, token: null);
        $this->assertSame(['COMPOSER_REPORT_PROJECT is not set.'], $withoutProject->validate(true));
    }

    public function testProjectMustBeASlug(): void
    {
        $errors = self::config(project: 'Klant X')->validate(true);

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('COMPOSER_REPORT_PROJECT must be a slug', $errors[0]);
    }

    public function testComposerCommandAndTimeouts(): void
    {
        $token = Secret::fromString('crt_1');
        $config = new ClientConfig('https://m.example.com', 'p', $token, [], 0.0, -1.0, true, '/', null);

        $this->assertSame(
            [
                'composer_bin must not be empty.',
                'timeout must be greater than 0.',
                'http_timeout must be greater than 0.',
            ],
            $config->validate(false),
        );
    }

    public static function config(
        ?string $endpoint = 'https://monitor.example.com/composer-report/ingest',
        ?string $project = 'example-project',
        ?string $token = 'crt_abababababababababababababababababababababababababababababababab',
        bool $directOnly = true,
    ): ClientConfig {
        return new ClientConfig(
            $endpoint,
            $project,
            $token === null ? null : Secret::fromString($token),
            ['composer'],
            120.0,
            15.0,
            $directOnly,
            '/var/www/site',
            null,
        );
    }
}
