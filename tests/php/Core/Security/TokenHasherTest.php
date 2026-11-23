<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Security;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Security\Secret;
use Webium\ComposerReport\Core\Security\TokenHasher;

class TokenHasherTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testGenerateGivesAPrefixedTokenAndItsHash(): void
    {
        [$token, $hash] = TokenHasher::generate();

        $this->assertMatchesRegularExpression('/^crt_[0-9a-f]{64}$/D', $token->reveal());
        $this->assertSame(hash('sha256', $token->reveal()), $hash);
        $this->assertNotSame($token->reveal(), $hash);
    }

    public function testTokensAreUnique(): void
    {
        $this->assertNotSame(TokenHasher::generate()[0]->reveal(), TokenHasher::generate()[0]->reveal());
    }

    public function testMatches(): void
    {
        [$token, $hash] = TokenHasher::generate();

        $this->assertTrue(TokenHasher::matches($token, $hash));
        $this->assertTrue(TokenHasher::matches(Secret::fromString($token->reveal()), $hash));
        $this->assertFalse(TokenHasher::matches(Secret::fromString($token->reveal() . 'x'), $hash));
        $this->assertFalse(TokenHasher::matches(TokenHasher::generate()[0], $hash));
    }

    public function testMatchesWithoutAStoredHashIsFalse(): void
    {
        [$token] = TokenHasher::generate();

        $this->assertFalse(TokenHasher::matches($token, null));
        $this->assertFalse(TokenHasher::matches($token, ''));
    }
}
