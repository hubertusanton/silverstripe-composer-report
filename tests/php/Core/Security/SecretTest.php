<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Security;

use LogicException;
use RuntimeException;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Security\Secret;

class SecretTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TOKEN = 'crt_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testRevealGivesTheValue(): void
    {
        $this->assertSame(self::TOKEN, Secret::fromString(self::TOKEN)->reveal());
    }

    public function testDumpsDoNotContainTheToken(): void
    {
        $secret = Secret::fromString(self::TOKEN);

        $this->assertStringNotContainsString(self::TOKEN, print_r($secret, true));
        $this->assertStringNotContainsString(self::TOKEN, print_r(['nested' => $secret], true));

        ob_start();
        var_dump($secret);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertStringContainsString('***', $dump);
    }

    public function testJsonDoesNotContainTheToken(): void
    {
        $json = json_encode(['token' => Secret::fromString(self::TOKEN)], JSON_THROW_ON_ERROR);

        $this->assertSame('{"token":"***"}', $json);
    }

    public function testSerializeThrows(): void
    {
        $this->expectException(LogicException::class);

        serialize(Secret::fromString(self::TOKEN));
    }

    public function testUnserializeThrows(): void
    {
        $this->expectException(LogicException::class);

        unserialize('O:' . strlen(Secret::class) . ':"' . Secret::class . '":1:{s:5:"value";s:3:"abc";}');
    }

    public function testThrownExceptionDoesNotContainTheToken(): void
    {
        $throwing = static function (Secret $token, string $plain): void {
            throw new RuntimeException('request failed');
        };

        try {
            $throwing(Secret::fromString(self::TOKEN), 'harmless');
            $this->fail('expected an exception');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $exception);
            $this->assertStringNotContainsString(self::TOKEN, print_r($this->argumentsUpToThisTest($exception), true));
        }
    }

    /**
     * The arguments of the frames from the throw up to this test method. Frames
     * above it belong to PHPUnit, whose arguments include other test objects
     * (with their own tokens) on PHPUnit 9.
     *
     * @return list<array<array-key, mixed>>
     */
    private function argumentsUpToThisTest(\Throwable $exception): array
    {
        $arguments = [];

        foreach ($exception->getTrace() as $frame) {
            $arguments[] = $frame['args'] ?? [];

            if (($frame['function'] ?? '') === 'testThrownExceptionDoesNotContainTheToken') {
                break;
            }
        }

        return $arguments;
    }
}
