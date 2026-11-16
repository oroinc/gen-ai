<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatterInterface;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\ValueType\DateValueType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the date value type.
 */
final class DateValueTypeTest extends TestCase
{
    private DateTimeFormatterInterface&MockObject $dateTimeFormatter;
    private TranslatorInterface&MockObject $translator;
    private DateValueType $valueType;

    #[\Override]
    protected function setUp(): void
    {
        $this->dateTimeFormatter = $this->createMock(DateTimeFormatterInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $calendarDateProvider = $this->createMock(CalendarDateProvider::class);
        $calendarDateProvider->expects(self::any())->method('getToday')->willReturn('2026-10-07');
        $this->valueType = new DateValueType($this->dateTimeFormatter, $this->translator, $calendarDateProvider);
    }

    public function testGetName(): void
    {
        self::assertSame(FactValueType::DATE, $this->valueType->getName());
    }

    public function testFormatForPromptHasNoRelativeText(): void
    {
        $this->dateTimeFormatter->expects(self::once())
            ->method('formatDate')
            ->with('2026-08-14', null, null, 'UTC')
            ->willReturn('Aug 14, 2026');
        $this->translator->expects(self::never())
            ->method('trans');

        self::assertSame(
            'Aug 14, 2026',
            $this->valueType->format($this->createFact('2026-08-14'))
        );
    }

    /**
     * @dataProvider relativeDataProvider
     */
    public function testFormatForDisplayAddsRelativeDayText(string $date, string $key, array $parameters): void
    {
        $this->dateTimeFormatter->expects(self::once())
            ->method('formatDate')
            ->with($date, null, null, 'UTC')
            ->willReturn('formatted');
        $this->translator->expects(self::once())
            ->method('trans')
            ->with($key, $parameters)
            ->willReturn('relative');

        self::assertSame(
            'formatted (relative)',
            $this->valueType->format($this->createFact($date), true)
        );
    }

    public static function relativeDataProvider(): array
    {
        return [
            'past' => ['2026-08-14', 'oro_smart_brief.relative.days_ago', ['%count%' => 54]],
            'today' => ['2026-10-07', 'oro_smart_brief.relative.today', []],
            'future' => ['2026-10-16', 'oro_smart_brief.relative.in_days', ['%count%' => 9]],
        ];
    }

    public function testRangeForPromptJoinsBothEnds(): void
    {
        $this->dateTimeFormatter->expects(self::exactly(2))
            ->method('formatDate')
            ->willReturnMap([
                ['2026-08-01', null, null, 'UTC', 'Aug 1, 2026'],
                ['2026-08-14', null, null, 'UTC', 'Aug 14, 2026'],
            ]);
        $this->translator->expects(self::once())
            ->method('trans')
            ->with('oro_smart_brief.range', ['%from%' => 'Aug 1, 2026', '%to%' => 'Aug 14, 2026'])
            ->willReturn('Aug 1, 2026 – Aug 14, 2026');

        self::assertSame(
            'Aug 1, 2026 – Aug 14, 2026',
            $this->valueType->format($this->createFact('2026-08-01/2026-08-14'))
        );
    }

    public function testRangeForDisplayHasNoRelativeText(): void
    {
        $this->dateTimeFormatter->expects(self::exactly(2))
            ->method('formatDate')
            ->willReturnArgument(0);
        $this->translator->expects(self::once())
            ->method('trans')
            ->with('oro_smart_brief.range', ['%from%' => '2026-08-14', '%to%' => '2026-10-07'])
            ->willReturn('2026-08-14 – 2026-10-07');

        self::assertSame(
            '2026-08-14 – 2026-10-07',
            $this->valueType->format($this->createFact('2026-08-14/2026-10-07'), true)
        );
    }

    public function testCanonicalizeAndRestore(): void
    {
        self::assertSame('2026-08-14', $this->valueType->canonicalize('2026-08-14'));
        self::assertSame('2026-08-14', $this->valueType->restore($this->valueType->canonicalize('2026-08-14')));
    }

    private function createFact(string $date): Fact
    {
        return new Fact('last_order_date', FactValueType::DATE, $date);
    }
}
