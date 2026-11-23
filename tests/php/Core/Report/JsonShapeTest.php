<?php

declare(strict_types=1);

namespace Webium\ComposerReport\Tests\Core\Report;

use SilverStripe\Dev\SapphireTest;
use Webium\ComposerReport\Core\Report\JsonShape;

class JsonShapeTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testEmptyArrayAndEmptyObjectAreBothMapsAndLists(): void
    {
        $fromArray = json_decode('[]', true);
        $fromObject = json_decode('{}', true);

        $this->assertSame([], JsonShape::asMap($fromArray));
        $this->assertSame([], JsonShape::asMap($fromObject));
        $this->assertSame([], JsonShape::asList($fromArray));
        $this->assertSame([], JsonShape::asList($fromObject));
    }

    public function testMapIsNotAList(): void
    {
        $map = ['dompdf/dompdf' => []];

        $this->assertSame($map, JsonShape::asMap($map));
        $this->assertNull(JsonShape::asList($map));
        $this->assertTrue(JsonShape::isMap($map));
        $this->assertFalse(JsonShape::isList($map));
    }

    public function testNonEmptyListIsNotAMap(): void
    {
        $list = [['name' => 'a']];

        $this->assertSame($list, JsonShape::asList($list));
        $this->assertNull(JsonShape::asMap($list));
    }

    public function testScalarsAreNeither(): void
    {
        foreach ([null, 'x', 1, true] as $value) {
            $this->assertNull(JsonShape::asMap($value));
            $this->assertNull(JsonShape::asList($value));
        }
    }
}
