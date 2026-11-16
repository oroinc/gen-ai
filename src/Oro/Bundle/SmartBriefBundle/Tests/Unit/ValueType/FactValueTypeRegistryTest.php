<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Value types are found by name, unknown names and duplicate names are programming errors.
 */
final class FactValueTypeRegistryTest extends TestCase
{
    public function testGetAndHas(): void
    {
        $date = $this->createValueType('date');
        $project = $this->createValueType('project_sku');
        $registry = new FactValueTypeRegistry([$date, $project]);

        self::assertSame($date, $registry->get('date'));
        self::assertSame($project, $registry->get('project_sku'));
        self::assertTrue($registry->has('date'));
        self::assertFalse($registry->has('money'));
    }

    public function testUnknownNameThrows(): void
    {
        $registry = new FactValueTypeRegistry([$this->createValueType('date')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown fact value type "money".');

        $registry->get('money');
    }

    public function testDuplicateNameThrows(): void
    {
        $registry = new FactValueTypeRegistry([$this->createValueType('date'), $this->createValueType('date')]);

        $this->expectException(\LogicException::class);

        $registry->get('date');
    }

    private function createValueType(string $name): FactValueTypeInterface
    {
        $valueType = $this->createMock(FactValueTypeInterface::class);
        $valueType->expects(self::any())
            ->method('getName')
            ->willReturn($name);

        return $valueType;
    }
}
