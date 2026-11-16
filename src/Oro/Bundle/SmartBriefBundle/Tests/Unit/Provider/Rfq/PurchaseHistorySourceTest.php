<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Rfq;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Query\PurchasedOrderCriteria;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\PurchaseHistorySource;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers the purchase facts: the count of distinct purchased products always, the
 * latest purchase in the user's calendar, the latest purchase per group returned for the last paid prices, the
 * numbers of the orders to link to (newest first, at most the link limit), nothing read without VIEW on Order and
 * OrderLineItem (null returned) or without requested products. The query itself is covered by the functional test.
 */
final class PurchaseHistorySourceTest extends TestCase
{
    use FactSourceTestTrait;

    /**
     * @dataProvider purchasesDataProvider
     */
    public function testFactsAndLatestPurchases(
        string $timezone,
        array $purchases,
        array $expectedFacts,
        array $expectedLatest,
        ?array $expectedRecords
    ): void {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::once())
            ->method('apply')
            ->willReturn($this->createQuery('getArrayResult', $purchases));
        $source = $this->createSource($aclHelper, $this->createDoctrine(), $timezone);

        $latest = null;
        $factSet = $this->collectFactSet(static function (FactSet $facts) use ($source, &$latest): void {
            $latest = $source->addFacts($facts, new Customer(), [11, 12]);
        });

        self::assertEquals($expectedFacts, array_values(iterator_to_array($factSet)), 'Dates only, no day-count fact.');
        self::assertSame($expectedLatest, $latest);
        self::assertSame(
            $expectedRecords,
            $factSet->getRecords('previously_purchased_product_count'),
            'The numbers of the orders to link to.'
        );
    }

    public static function purchasesDataProvider(): array
    {
        return [
            'never bought: a zero count only' => [
                'UTC',
                [],
                [new Fact('previously_purchased_product_count', FactValueType::INTEGER, 0, false)],
                [],
                null,
            ],
            'one product in two units counts once; the latest of all groups' => [
                'UTC',
                [
                    self::purchase(1, 'SO-1', '2026-08-01 10:00:00', 11, 'pc'),
                    self::purchase(2, 'SO-2', '2026-08-14 15:00:00', 11, 'kg'),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 1),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-14'),
                ],
                [
                    self::latest(11, 'pc', '2026-08-01 10:00:00'),
                    self::latest(11, 'kg', '2026-08-14 15:00:00'),
                ],
                ['SO-2', 'SO-1'],
            ],
            'two products bought last on different days: the range of their last purchases' => [
                'America/Los_Angeles',
                // early UTC is the previous day in Los Angeles
                [
                    self::purchase(1, 'SO-1', '2026-08-14 03:00:00', 11, 'pc'),
                    self::purchase(2, 'SO-2', '2026-08-01 10:00:00', 12, null),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 2),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-01/2026-08-13'),
                ],
                [
                    self::latest(11, 'pc', '2026-08-14 03:00:00'),
                    self::latest(12, null, '2026-08-01 10:00:00'),
                ],
                ['SO-1', 'SO-2'],
            ],
            'two products bought last on the same day: one date' => [
                'UTC',
                [
                    self::purchase(1, 'SO-1', '2026-08-14 09:00:00', 11, 'pc'),
                    self::purchase(2, 'SO-2', '2026-08-14 17:00:00', 12, 'pc'),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 2),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-14'),
                ],
                [
                    self::latest(11, 'pc', '2026-08-14 09:00:00'),
                    self::latest(12, 'pc', '2026-08-14 17:00:00'),
                ],
                ['SO-2', 'SO-1'],
            ],
            'the range starts at the earliest last purchase, not at an older purchase of the same product' => [
                'UTC',
                [
                    self::purchase(1, 'SO-1', '2026-06-01 10:00:00', 11, 'pc'),
                    self::purchase(2, 'SO-2', '2026-08-10 10:00:00', 11, 'pc'),
                    self::purchase(3, 'SO-3', '2026-08-14 10:00:00', 12, 'pc'),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 2),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-10/2026-08-14'),
                ],
                [
                    self::latest(11, 'pc', '2026-08-10 10:00:00'),
                    self::latest(12, 'pc', '2026-08-14 10:00:00'),
                ],
                ['SO-3', 'SO-2', 'SO-1'],
            ],
            'an order with several requested products is linked once; orders without a number are not linked' => [
                'UTC',
                [
                    self::purchase(1, 'SO-1', '2026-08-01 10:00:00', 11, 'pc'),
                    self::purchase(1, 'SO-1', '2026-08-01 10:00:00', 12, 'pc'),
                    self::purchase(2, null, '2026-08-05 10:00:00', 11, 'pc'),
                    self::purchase(3, ' ', '2026-08-06 10:00:00', 12, 'pc'),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 2),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-05/2026-08-06'),
                ],
                [
                    self::latest(11, 'pc', '2026-08-05 10:00:00'),
                    self::latest(12, 'pc', '2026-08-06 10:00:00'),
                ],
                ['SO-1'],
            ],
            'orders created at the same time: the higher id first' => [
                'UTC',
                [
                    self::purchase(7, 'SO-7', '2026-08-01 10:00:00', 11, 'pc'),
                    self::purchase(9, 'SO-9', '2026-08-01 10:00:00', 11, 'pc'),
                ],
                [
                    new Fact('previously_purchased_product_count', FactValueType::INTEGER, 1),
                    new Fact('last_purchase_date', FactValueType::DATE, '2026-08-01'),
                ],
                [self::latest(11, 'pc', '2026-08-01 10:00:00')],
                ['SO-9', 'SO-7'],
            ],
        ];
    }

    public function testMoreOrdersThanTheLinkLimitMeanNoRecords(): void
    {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::once())
            ->method('apply')
            ->willReturn($this->createQuery('getArrayResult', array_map(
                static fn (int $id) => self::purchase($id, 'SO-' . $id, '2026-09-01 10:00:00', 11, 'item'),
                range(1, FactSet::RECORD_LIMIT + 1)
            )));
        $source = $this->createSource($aclHelper, $this->createDoctrine());

        $facts = $this->collectFactSet(static fn (FactSet $facts) => $source->addFacts($facts, new Customer(), [11]));

        self::assertNull($facts->getRecords('previously_purchased_product_count'));
    }

    public function testNoRequestedProductsMeansAZeroCountWithoutAQuery(): void
    {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::never())->method('apply');
        $source = $this->createSource($aclHelper, $this->createDoctrine());

        $latest = null;
        $facts = $this->collectFacts(static function (FactSet $facts) use ($source, &$latest): void {
            $latest = $source->addFacts($facts, new Customer(), []);
        });

        self::assertEquals([new Fact('previously_purchased_product_count', FactValueType::INTEGER, 0, false)], $facts);
        self::assertSame([], $latest);
    }

    /**
     * @dataProvider partialAccessDataProvider
     */
    public function testWithoutViewOnOrdersOrOrderLinesNothingIsRead(array $viewable): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManagerForClass');
        $source = new PurchaseHistorySource(
            $doctrine,
            $this->createMock(AclHelper::class),
            $this->createMock(PurchasedOrderCriteria::class),
            $this->createAccessProvider(...$viewable),
            $this->createCalendarDateProvider()
        );

        $facts = new FactSet();
        self::assertNull($source->addFacts($facts, new Customer(), [11]));
        self::assertCount(0, $facts);
    }

    public static function partialAccessDataProvider(): array
    {
        return ['only orders' => [[Order::class]], 'only order lines' => [[OrderLineItem::class]], 'neither' => [[]]];
    }

    /**
     * A row as the query returns it: ids as strings, the creation date in the database format (UTC).
     */
    private static function purchase(
        int $orderId,
        ?string $identifier,
        string $createdAt,
        int $productId,
        ?string $unit
    ): array {
        return [
            'orderId' => (string)$orderId,
            'identifier' => $identifier,
            'createdAt' => $createdAt,
            'productId' => (string)$productId,
            'unitCode' => $unit,
            'currency' => 'USD',
            'priceType' => '10',
        ];
    }

    private static function latest(int $productId, ?string $unit, string $latestAt): array
    {
        return [
            'productId' => $productId,
            'unitCode' => $unit,
            'currency' => 'USD',
            'priceType' => 10,
            'latestAt' => $latestAt,
        ];
    }

    private function createSource(
        AclHelper $aclHelper,
        ManagerRegistry $doctrine,
        string $timezone = 'UTC'
    ): PurchaseHistorySource {
        return new PurchaseHistorySource(
            $doctrine,
            $aclHelper,
            $this->createMock(PurchasedOrderCriteria::class),
            $this->createAccessProvider(Order::class, OrderLineItem::class),
            $this->createCalendarDateProvider($timezone)
        );
    }
}
