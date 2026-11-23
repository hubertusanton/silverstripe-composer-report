<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Client;

use LogicException;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Dev\TestOnly;
use Webium\ComposerReport\Core\Composer\ComposerRunner;

/**
 * Injector factory for ComposerRunner in tests. createWithArgs() always builds
 * a new object, so a registered service would be ignored; this factory hands
 * out a prepared runner and records the constructor arguments.
 */
class FakeRunnerFactory implements Factory, TestOnly
{
    public static ?ComposerRunner $runner = null;

    /**
     * @var array<int|string, mixed>
     */
    public static array $lastParams = [];

    public function create(string $service, array $params = []): ?object
    {
        self::$lastParams = $params;

        return self::$runner ?? throw new LogicException('Set FakeRunnerFactory::$runner first');
    }
}
