<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Report\UpdateLevel;

class UpdateLevelTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @return array<string, array{string, string, UpdateLevel}>
     */
    public static function provideVersions(): array
    {
        return [
            'major with v prefix'       => ['v2.0.8', 'v3.1.6', UpdateLevel::Major],
            'minor'                     => ['4.1.0', '4.2.0', UpdateLevel::Minor],
            'minor with two segments'   => ['1.1', '1.2', UpdateLevel::Minor],
            'patch with four segments'  => ['1.11.99.4', '1.11.99.5', UpdateLevel::Patch],
            'patch'                     => ['2.4.4', '2.4.5', UpdateLevel::Patch],
            'missing segment counts 0'  => ['1.1', '1.1.1', UpdateLevel::Patch],
            'stability suffix stripped' => ['1.0.0-beta1', '2.0.0', UpdateLevel::Major],
            'glued stability suffix'    => ['1.0.0RC1', '1.1.0', UpdateLevel::Minor],
            'dev suffix stripped'       => ['2.1-dev', '2.2.0', UpdateLevel::Minor],
            'equal versions'            => ['1.2.3', '1.2.3', UpdateLevel::Unknown],
            'equal apart from prefix'   => ['v1.2.3', '1.2.3', UpdateLevel::Unknown],
            'branch version'            => ['dev-main 1a2b3c4', '1.0.0', UpdateLevel::Unknown],
            'none matched'              => ['1.0.0', '[none matched]', UpdateLevel::Unknown],
            'x-dev'                     => ['2.x-dev', '3.0.0', UpdateLevel::Unknown],
            'empty'                     => ['', '1.0.0', UpdateLevel::Unknown],
        ];
    }

    /**
     * @dataProvider provideVersions
     */
    #[DataProvider('provideVersions')]
    public function testBetween(string $current, string $latest, UpdateLevel $expected): void
    {
        $this->assertSame($expected, UpdateLevel::between($current, $latest));
    }
}
