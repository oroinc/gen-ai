<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatter;
use Oro\Bundle\LocaleBundle\Formatter\Factory\IntlNumberFormatterFactory;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\ValueType\DateValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;
use Oro\Bundle\SmartBriefBundle\ValueType\IntegerValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\MoneyValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\PercentValueType;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the value types with the real platform formatters, so the expected literals are proven end to end; the
 * user's formatting locale comes from the locale settings, as in the application.
 */
final class RealFormatterValueTypesTest extends TestCase
{
    /**
     * @dataProvider formatDataProvider
     */
    public function testFormatWithRealFormatters(
        string $valueTypeClass,
        string $type,
        mixed $value,
        string $formattingCode,
        string $expected
    ): void {
        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->expects(self::any())->method('getLocale')->willReturn($formattingCode);
        $localeSettings->expects(self::any())->method('getCurrency')->willReturn('USD');
        $localeSettings->expects(self::any())->method('getTimeZone')->willReturn('UTC');
        $localeSettings->expects(self::any())
            ->method('getCurrencySymbolByCurrency')
            ->willReturnCallback(static fn (string $currency) => ['USD' => '$', 'EUR' => '€'][$currency]);
        $numberFormatter = new NumberFormatter($localeSettings, new IntlNumberFormatterFactory($localeSettings));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::any())
            ->method('trans')
            ->willReturnCallback(static fn (string $id) => $id);

        /** @var FactValueTypeInterface $valueType */
        $valueType = match ($valueTypeClass) {
            DateValueType::class => new DateValueType(
                new DateTimeFormatter($localeSettings, $translator),
                $translator,
                $this->createMock(CalendarDateProvider::class)
            ),
            PercentValueType::class => new PercentValueType($numberFormatter, $translator),
            default => new $valueTypeClass($numberFormatter),
        };

        // intl uses non-breaking spaces between amount and symbol in some locales; the test does not care
        $normalize = static fn (string $text) => str_replace("\u{00A0}", ' ', $text);

        self::assertSame($expected, $normalize($valueType->format(new Fact('key', $type, $value))));
        self::assertSame(
            $expected,
            $normalize($valueType->format(
                new Fact('key', $type, $valueType->restore($valueType->canonicalize($value)))
            )),
            'a value restored from the cache formats the same as a fresh one'
        );
    }

    public static function formatDataProvider(): array
    {
        return [
            'date en_US' => [DateValueType::class, FactValueType::DATE, '2026-08-14', 'en_US', 'Aug 14, 2026'],
            'date de_DE' => [DateValueType::class, FactValueType::DATE, '2026-08-14', 'de_DE', '14.08.2026'],
            'integer' => [IntegerValueType::class, FactValueType::INTEGER, 1200, 'en_US', '1,200'],
            'percent positive' => [PercentValueType::class, FactValueType::PERCENT, '8.3', 'en_US', '+8.3%'],
            'percent negative' => [PercentValueType::class, FactValueType::PERCENT, '-2.04', 'en_US', '-2.0%'],
            'percent rounds to zero' => [PercentValueType::class, FactValueType::PERCENT, '-0.04', 'en_US', '0.0%'],
            'percent de_DE' => [PercentValueType::class, FactValueType::PERCENT, '8.3', 'de_DE', '+8,3 %'],
            'money' => [
                MoneyValueType::class,
                FactValueType::MONEY,
                Price::create('12480', 'USD'),
                'en_US',
                '$12,480.00',
            ],
            'money scale 4' => [
                MoneyValueType::class,
                FactValueType::MONEY,
                Price::create('12480.0000', 'USD'),
                'en_US',
                '$12,480.00',
            ],
            'money de_DE' => [
                MoneyValueType::class,
                FactValueType::MONEY,
                Price::create('12480.5', 'EUR'),
                'de_DE',
                '12.480,50 €',
            ],
        ];
    }
}
