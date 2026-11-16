<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\PercentValueType;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the percent value type.
 */
final class PercentValueTypeTest extends TestCase
{
    /**
     * @dataProvider formatDataProvider
     */
    public function testFormat(string $value, string $passed, string $formatted, string $expected): void
    {
        $numberFormatter = $this->createMock(NumberFormatter::class);
        $numberFormatter->expects(self::once())
            ->method('formatPercent')
            ->with(
                $passed,
                [\NumberFormatter::MIN_FRACTION_DIGITS => 1, \NumberFormatter::MAX_FRACTION_DIGITS => 1]
            )
            ->willReturn($formatted);
        $valueType = new PercentValueType($numberFormatter, $this->createMock(TranslatorInterface::class));

        self::assertSame(FactValueType::PERCENT, $valueType->getName());
        self::assertSame(
            $expected,
            $valueType->format(
                new Fact('list_gap_percent_range', FactValueType::PERCENT, $value)
            )
        );
    }

    public static function formatDataProvider(): array
    {
        return [
            'positive gets a plus sign' => ['8.3', '0.083', '8.3%', '+8.3%'],
            'negative keeps the formatter sign' => ['-2.04', '-0.020', '-2.0%', '-2.0%'],
            'zero has no sign' => ['0', '0.000', '0.0%', '0.0%'],
            'rounds to zero without sign' => ['-0.04', '0.000', '0.0%', '0.0%'],
        ];
    }

    public function testCanonicalizeAndRestore(): void
    {
        $valueType = new PercentValueType(
            $this->createMock(NumberFormatter::class),
            $this->createMock(TranslatorInterface::class)
        );

        self::assertSame('8.3', $valueType->canonicalize('8.3'));
        self::assertSame('8.3', $valueType->canonicalize('8.25'));
        self::assertSame('8.3', $valueType->canonicalize('8.3000'));
        self::assertSame('-2.0', $valueType->canonicalize('-2'));
        self::assertSame('8.3', $valueType->restore($valueType->canonicalize('8.3')));
        self::assertSame('-13.0/8.3', $valueType->canonicalize('-13/8.25'), 'Each end of a range.');
    }

    public function testARangeJoinsItsFormattedEnds(): void
    {
        $numberFormatter = $this->createMock(NumberFormatter::class);
        $numberFormatter->expects(self::exactly(2))
            ->method('formatPercent')
            ->willReturnCallback(static fn (string $value) => ['-0.130' => '-13.0%', '0.050' => '5.0%'][$value]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('oro_smart_brief.range', ['%from%' => '-13.0%', '%to%' => '+5.0%'])
            ->willReturn('-13.0% – +5.0%');

        self::assertSame(
            '-13.0% – +5.0%',
            (new PercentValueType($numberFormatter, $translator))->format(
                new Fact('list_gap_percent_range', FactValueType::PERCENT, '-13.0/5.0')
            )
        );
    }
}
