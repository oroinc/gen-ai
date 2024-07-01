<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Context;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use PHPUnit\Framework\TestCase;

final class ContextItemTest extends TestCase
{
    public function testContextItem(): void
    {
        $contextItem = new ContextItem('key', 'value');

        self::assertEquals('key', $contextItem->getKey());
        self::assertEquals('value', $contextItem->getValue());
        self::assertEquals('key value', $contextItem->getTextRepresentation());

        $contextItem = new ContextItem('key', 4);

        self::assertEquals('key', $contextItem->getKey());
        self::assertEquals(4, $contextItem->getValue());
        self::assertEquals('key 4', $contextItem->getTextRepresentation());
    }
}
