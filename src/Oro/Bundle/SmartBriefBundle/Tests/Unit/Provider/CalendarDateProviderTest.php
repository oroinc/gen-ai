<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calendar dates in the user's timezone.
 */
final class CalendarDateProviderTest extends TestCase
{
    public function testTodayUsesUserTimezone(): void
    {
        $provider = $this->createProvider('Pacific/Kiritimati');

        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d'),
            $provider->getToday()
        );
    }

    /**
     * @dataProvider toDateDataProvider
     */
    public function testToDateConvertsStoredUtcDatetime(string $timezone, string $utc, string $expected): void
    {
        $created = new \DateTime($utc, new \DateTimeZone('UTC'));

        self::assertSame($expected, $this->createProvider($timezone)->toDate($created));
        self::assertSame($utc, $created->format('Y-m-d H:i:s'), 'the input is not mutated');
    }

    public static function toDateDataProvider(): array
    {
        return [
            'next day ahead of UTC' => ['Europe/Berlin', '2026-10-07 23:30:00', '2026-10-08'],
            'UTC' => ['UTC', '2026-10-07 23:30:00', '2026-10-07'],
            'previous day behind UTC' => ['America/Los_Angeles', '2026-08-14 03:00:00', '2026-08-13'],
        ];
    }

    public function testHasNoDayCountMethod(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(CalendarDateProvider::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        self::assertSame(['__construct', 'getToday', 'toDate'], $methods);
    }

    private function createProvider(string $timezone): CalendarDateProvider
    {
        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->expects(self::any())
            ->method('getTimeZone')
            ->willReturn($timezone);

        return new CalendarDateProvider($localeSettings);
    }
}
