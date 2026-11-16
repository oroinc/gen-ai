<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\IntegerValueType;
use PHPUnit\Framework\TestCase;

/**
 * Covers the integer value type.
 */
final class IntegerValueTypeTest extends TestCase
{
    public function testFormat(): void
    {
        $numberFormatter = $this->createMock(NumberFormatter::class);
        $numberFormatter->expects(self::once())
            ->method('formatDecimal')
            ->with(1200)
            ->willReturn('1,200');
        $valueType = new IntegerValueType($numberFormatter);

        self::assertSame(FactValueType::INTEGER, $valueType->getName());
        self::assertSame(
            '1,200',
            $valueType->format(
                new Fact('open_rfq_count', FactValueType::INTEGER, 1200)
            )
        );
    }

    public function testCanonicalizeAndRestore(): void
    {
        $valueType = new IntegerValueType($this->createMock(NumberFormatter::class));

        self::assertSame(1200, $valueType->canonicalize(1200));
        self::assertSame(1200, $valueType->canonicalize('1200'));
        self::assertSame(1200, $valueType->restore($valueType->canonicalize(1200)));
    }
}
