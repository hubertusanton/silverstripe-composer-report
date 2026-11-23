<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Yaml\Yaml;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Core\Security\TokenHasher;
use Webium\ComposerReport\Server\Model\ComposerReportProject;
use Webium\ComposerReport\Server\ServerActivation;

/**
 * Shared setup for server tests: the runtime guard on, an empty rate limiter
 * cache, and the environment restored afterwards.
 */
trait ServerTestState
{
    /**
     * @var array<string, mixed>
     */
    private array $savedEnvironment = [];

    protected function enableServer(bool $enabled = true): void
    {
        if ($this->savedEnvironment === []) {
            $this->savedEnvironment = Environment::getVariables();
        }

        Environment::setEnv(ServerActivation::ENV_VARIABLE, $enabled);
        Injector::inst()->get(CacheInterface::class . '.RateLimiter')->clear();
    }

    protected function restoreEnvironment(): void
    {
        if ($this->savedEnvironment !== []) {
            Environment::setVariables($this->savedEnvironment);
            $this->savedEnvironment = [];
        }
    }

    /**
     * A project with a fresh token.
     *
     * @return array{ComposerReportProject, Secret}
     */
    protected function createProject(string $slug = 'example-project', bool $active = true): array
    {
        [$token, $hash] = TokenHasher::generate();

        $project = ComposerReportProject::create();
        $project->Title = 'Example project';
        $project->Slug = $slug;
        $project->Active = $active;
        $project->TokenHash = $hash;
        $project->write();

        return [$project, $token];
    }

    /**
     * The BasicAuthMiddleware URL patterns from _config/server-enabled.yml.
     *
     * @return array<string, mixed>
     */
    protected static function basicAuthPatterns(): array
    {
        [, $body] = self::serverEnabledYaml();
        $injector = $body['SilverStripe\\Core\\Injector\\Injector'];

        return $injector['SilverStripe\\Security\\BasicAuthMiddleware']['properties']['URLPatterns'];
    }

    /**
     * The two YAML documents of _config/server-enabled.yml: header and body.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    protected static function serverEnabledYaml(): array
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/_config/server-enabled.yml');
        $documents = preg_split('/^---\s*$/m', $contents) ?: [];

        return [Yaml::parse($documents[1] ?? ''), Yaml::parse($documents[2] ?? '')];
    }
}
