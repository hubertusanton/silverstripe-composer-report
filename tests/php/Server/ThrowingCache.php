<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Server;

use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Dev\TestOnly;

/**
 * A cache that is down, for the fail-open test of the rate limiter.
 */
class ThrowingCache implements CacheInterface, TestOnly
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException('cache down');
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache down');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('cache down');
    }

    public function clear(): bool
    {
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new RuntimeException('cache down');
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        throw new RuntimeException('cache down');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new RuntimeException('cache down');
    }

    public function has(string $key): bool
    {
        throw new RuntimeException('cache down');
    }
}
