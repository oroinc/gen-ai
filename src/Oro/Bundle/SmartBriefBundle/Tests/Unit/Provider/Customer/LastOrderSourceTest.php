<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Customer;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastOrderSource;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers the last order facts: the date in the user's calendar,
 * the raw total, a missing total or order skipped, nothing read without VIEW on Order. The query itself is covered
 * by the functional test.
 */
final class LastOrderSourceTest extends TestCase
{
    use FactSourceTestTrait;

    /**
     * @dataProvider timezoneDataProvider
     */
    public function testDateInTheUserCalendarAndTheRawTotal(
        string $timezone,
        string $createdAtUtc,
        string $expectedDate
    ): void {
        $source = $this->createSource(
            [
                'createdAt' => new \DateTimeImmutable($createdAtUtc, new \DateTimeZone('UTC')),
                'identifier' => 'SO-1042',
                'total' => '12480.0000',
                'currency' => 'USD',
            ],
            $timezone
        );

        $facts = $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Customer()));

        self::assertEquals(
            [
                new Fact('last_order_date', FactValueType::DATE, $expectedDate),
                new Fact('last_order_total', FactValueType::MONEY, Price::create('12480.0000', 'USD')),
            ],
            array_values(iterator_to_array($facts)),
            'Dates only, no day-count fact.'
        );
        self::assertSame(['SO-1042'], $facts->getRecords('last_order_date'), 'The order number to link to.');
    }

    public static function timezoneDataProvider(): array
    {
        return [
            'Berlin, afternoon UTC' => ['Europe/Berlin', '2026-08-14 15:00:00', '2026-08-14'],
            'Berlin, late UTC crosses to the next day' => ['Europe/Berlin', '2026-08-14 23:30:00', '2026-08-15'],
            'Los Angeles, early UTC' => ['America/Los_Angeles', '2026-08-14 03:00:00', '2026-08-13'],
        ];
    }

    /**
     * @dataProvider missingTotalDataProvider
     */
    public function testMissingTotalSkipsOnlyTheTotal(?string $total, ?string $currency): void
    {
        $source = $this->createSource([
            'createdAt' => new \DateTimeImmutable('2026-08-14'),
            'identifier' => null,
            'total' => $total,
            'currency' => $currency,
        ]);

        $facts = $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Customer()));

        self::assertSame(['last_order_date'], array_map(static fn (Fact $fact) => $fact->key, $facts));
    }

    public static function missingTotalDataProvider(): array
    {
        return ['no total' => [null, 'USD'], 'no currency' => ['5.0000', null], 'empty currency' => ['5.0000', '']];
    }

    /**
     * @dataProvider noOrderDataProvider
     */
    public function testNoOrderMeansNoFacts(?array $row): void
    {
        $source = $this->createSource($row);

        self::assertSame([], $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Customer())));
    }

    public static function noOrderDataProvider(): array
    {
        return [
            'no order' => [null],
            'no creation time' => [['createdAt' => null, 'total' => '5.0000', 'currency' => 'USD']],
        ];
    }

    public function testWithoutViewOnOrderNothingIsRead(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManagerForClass');
        $source = new LastOrderSource(
            $doctrine,
            $this->createMock(AclHelper::class),
            $this->createAccessProvider(),
            $this->createCalendarDateProvider()
        );

        self::assertSame([], $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Customer())));
    }

    private function createSource(?array $row, string $timezone = 'UTC'): LastOrderSource
    {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::once())
            ->method('apply')
            ->willReturn($this->createQuery('getOneOrNullResult', $row));

        return new LastOrderSource(
            $this->createDoctrine(),
            $aclHelper,
            $this->createAccessProvider(Order::class),
            $this->createCalendarDateProvider($timezone)
        );
    }
}
