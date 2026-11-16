<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\MoneyValueType;
use PHPUnit\Framework\TestCase;

/**
 * Covers the money value type.
 */
final class MoneyValueTypeTest extends TestCase
{
    public function testFormat(): void
    {
        $numberFormatter = $this->createMock(NumberFormatter::class);
        $numberFormatter->expects(self::once())
            ->method('formatCurrency')
            ->with('12480', 'USD')
            ->willReturn('$12,480.00');
        $valueType = new MoneyValueType($numberFormatter);

        self::assertSame(FactValueType::MONEY, $valueType->getName());
        self::assertSame(
            '$12,480.00',
            $valueType->format(
                new Fact('last_order_total', FactValueType::MONEY, Price::create('12480', 'USD'))
            )
        );
    }

    public function testCanonicalizeIsScaleFourAndIndependentOfInputScale(): void
    {
        $valueType = new MoneyValueType($this->createMock(NumberFormatter::class));

        $expected = ['amount' => '12480.0000', 'currency' => 'USD'];
        self::assertSame($expected, $valueType->canonicalize(Price::create('12480', 'USD')));
        self::assertSame($expected, $valueType->canonicalize(Price::create('12480.0000', 'USD')));
        self::assertSame(
            ['amount' => '0.1235', 'currency' => 'EUR'],
            $valueType->canonicalize(Price::create('0.12345', 'EUR'))
        );
    }

    public function testRestore(): void
    {
        $valueType = new MoneyValueType($this->createMock(NumberFormatter::class));

        $restored = $valueType->restore($valueType->canonicalize(Price::create('12480', 'USD')));

        self::assertEquals(Price::create('12480.0000', 'USD'), $restored);
    }

    public function testRejectsNonMoneyValue(): void
    {
        $valueType = new MoneyValueType($this->createMock(NumberFormatter::class));

        $this->expectException(\InvalidArgumentException::class);

        $valueType->canonicalize('12480');
    }

    public function testRestoreRejectsIncompleteCanonical(): void
    {
        $valueType = new MoneyValueType($this->createMock(NumberFormatter::class));

        $this->expectException(\InvalidArgumentException::class);

        $valueType->restore(['amount' => '1']);
    }
}
