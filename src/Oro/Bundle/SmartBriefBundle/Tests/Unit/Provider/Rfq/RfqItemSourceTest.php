<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Rfq;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\PricingBundle\Entity\ProductPrice;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\RFPBundle\Entity\RequestProductItem;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\LastPaidPriceSource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\ListPriceSource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqItemSource;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use PHPUnit\Framework\TestCase;

/**
 * Covers the RFQ price comparison: which price sources run, comparability (kits, unit and currency mismatches), the
 * percentage arithmetic and the two ranges. The item query itself is covered by the functional tests.
 */
final class RfqItemSourceTest extends TestCase
{
    use RfqItemTestTrait;

    public function testTheRangesOfTheListAndLastPaidDifferences(): void
    {
        $facts = $this->facts(
            [
                $this->item(1, 1, 7, '9.10', 'USD', 'pc', 'ABC-123', '100'),
                $this->item(1, 2, 7, '9.50', 'USD', 'pc', 'ABC-123', '10'),
            ],
            [1 => Price::create('8.4000', 'USD'), 2 => Price::create('8.8000', 'USD')],
            $this->lastPaid([[7, 'pc', Price::create('8.9000', 'USD')]])
        );

        self::assertEquals(
            [
                new Fact('list_gap_percent_range', FactValueType::PERCENT, '8.0/8.3'),
                new Fact('last_paid_gap_percent_range', FactValueType::PERCENT, '2.2/6.7'),
            ],
            $facts,
            'Only the ranges, from the lowest to the highest; the items themselves are not facts.'
        );
    }

    public function testTheRangeCoversEveryQuantity(): void
    {
        // 20 quantities at -10 % against the list price and 1 at +5 %
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = $this->item($i, $i, $i, '0.90', 'USD', 'pc', 'SKU-' . $i);
        }
        $items[] = $this->item(21, 21, 21, '1.05', 'USD', 'pc', 'SKU-21');

        self::assertEquals(
            [new Fact('list_gap_percent_range', FactValueType::PERCENT, '-10.0/5.0')],
            $this->facts($items, array_fill_keys(range(1, 21), Price::create('1.0000', 'USD')), null)
        );
    }

    public function testOnlyTheLastPaidRangeWithoutListPrices(): void
    {
        // the user may not view product prices: no list price, so no list price range either
        $facts = $this->facts(
            [
                $this->item(1, 1, 7, '8.00', 'USD', 'pc', 'A'),
                $this->item(2, 2, 8, '9.00', 'USD', 'pc', 'B'),
            ],
            [],
            $this->lastPaid([[7, 'pc', Price::create('10.0000', 'USD')], [8, 'pc', Price::create('10.0000', 'USD')]])
        );

        self::assertEquals([new Fact('last_paid_gap_percent_range', FactValueType::PERCENT, '-20.0/-10.0')], $facts);
    }

    public function testNoRangeWithoutAnyDifference(): void
    {
        self::assertSame([], $this->facts([$this->item(1, 1, 1, '40.00', 'USD', 'pc', 'NO-PRICE')], [], []));
    }

    public function testEqualEndsGiveOneValue(): void
    {
        $facts = $this->facts(
            [
                $this->item(1, 1, 1, '0.90', 'USD', 'pc', 'A'),
                $this->item(2, 2, 2, '0.90', 'USD', 'pc', 'B'),
            ],
            [1 => Price::create('1.0000', 'USD'), 2 => Price::create('1.0000', 'USD')],
            null
        );

        self::assertEquals([new Fact('list_gap_percent_range', FactValueType::PERCENT, '-10.0')], $facts);
    }

    public function testKitsAndUnitOrCurrencyMismatchesAreNotCompared(): void
    {
        // item B: bought only in USD; item E: bought only in unit "set"; the kit has a list price
        $facts = $this->facts(
            [
                $this->item(1, 1, 2, '4.00', 'EUR', 'kg', 'B-1'),
                $this->item(2, 2, 3, '5.00', 'USD', 'pc', 'E-1'),
                $this->item(3, 3, 4, '9.00', 'USD', 'pc', 'KIT-1', isKit: true),
            ],
            [3 => Price::create('10.0000', 'USD')],
            $this->lastPaid([[2, 'kg', Price::create('3.5000', 'USD')], [3, 'set', Price::create('5.0000', 'USD')]])
        );

        self::assertSame([], $facts);
    }

    public function testAListPriceInAnotherCurrencyIsNotCompared(): void
    {
        self::assertSame([], $this->facts(
            [$this->item(1, 1, 2, '4.00', 'EUR', 'kg', 'B-1')],
            [1 => Price::create('3.0000', 'USD')],
            null
        ));
    }

    /**
     * @dataProvider percentDataProvider
     */
    public function testPercentage(string $requested, string $reference, ?string $expectedPercent): void
    {
        $byKey = $this->byKey($this->facts(
            [$this->item(1, 1, 1, $requested, 'USD', 'pc', 'GAP')],
            [1 => Price::create($reference, 'USD')],
            null
        ));

        self::assertSame($expectedPercent, ($byKey['list_gap_percent_range'] ?? null)?->value);
    }

    public static function percentDataProvider(): array
    {
        return [
            'above the reference' => ['9.10', '8.40', '8.3'],
            'above, small difference' => ['9.10', '8.90', '2.2'],
            'below, -2.25 rounds away from zero' => ['7.82', '8.00', '-2.3'],
            'equal prices' => ['8.00', '8.0000', '0.0'],
            'zero reference: no percentage' => ['1.50', '0.00', null],
            'raw four-decimal money columns' => ['9.1000', '8.4000', '8.3'],
        ];
    }

    /**
     * List prices only with a website and VIEW on product prices, priced with the RFQ's own
     * customer (a guest's too); last paid prices only for a customer whose purchases may be read. A kit is not
     * priced.
     *
     * @dataProvider gatingDataProvider
     */
    public function testPriceSourcesAreGated(
        bool $withWebsite,
        bool $withCustomer,
        bool $readPurchases,
        bool $readListPrices,
        bool $expectListPrices,
        bool $expectLastPaid
    ): void {
        $item = $this->item(1, 1, 7, '9.10', 'USD', 'pc', 'ABC-123', '100');
        $kit = $this->item(2, 2, 8, '9.00', 'USD', 'pc', 'KIT-1', isKit: true);
        // the RFQ always has its customer (a guest's too); the facts get it only when it is not a guest's
        $rfqCustomer = new Customer();
        $request = (new Request())->setWebsite($withWebsite ? new Website() : null)->setCustomer($rfqCustomer);
        $listPriceSource = $this->createMock(ListPriceSource::class);
        $listPriceSource->expects($expectListPrices ? self::once() : self::never())
            ->method('getListPrices')
            ->with([$item], self::isInstanceOf(Website::class), self::identicalTo($rfqCustomer))
            ->willReturn([]);
        $lastPaidPriceSource = $this->createMock(LastPaidPriceSource::class);
        $lastPaidPriceSource->expects($expectLastPaid ? self::once() : self::never())
            ->method('getLastPaidPrices')
            ->with(self::identicalTo($rfqCustomer), [7], [])
            ->willReturn([]);
        $source = $this->createSource(
            [$item, $kit],
            $readListPrices ? [ProductPrice::class] : [],
            $listPriceSource,
            $lastPaidPriceSource
        );

        $facts = new FactSet();
        $source->addFacts($facts, $request, $withCustomer ? $rfqCustomer : null, $readPurchases ? [] : null);

        self::assertTrue($facts->isEmpty());
    }

    public static function gatingDataProvider(): array
    {
        return [
            'website and readable purchases' => [true, true, true, true, true, true],
            'no website: no list prices' => [false, true, true, true, false, true],
            'prices not readable: no list prices' => [true, true, true, false, false, true],
            'guest: priced with its own customer, no last paid' => [true, false, true, true, true, false],
            'purchases not readable: no last paid' => [true, true, false, true, true, false],
        ];
    }

    public function testNoComparableItemsReadNoPrices(): void
    {
        $listPriceSource = $this->createMock(ListPriceSource::class);
        $listPriceSource->expects(self::never())->method('getListPrices');
        $lastPaidPriceSource = $this->createMock(LastPaidPriceSource::class);
        $lastPaidPriceSource->expects(self::never())->method('getLastPaidPrices');
        $source = $this->createSource(
            [$this->item(1, 1, 8, '9.00', 'USD', 'pc', 'KIT-1', isKit: true)],
            [ProductPrice::class],
            $listPriceSource,
            $lastPaidPriceSource
        );

        $facts = new FactSet();
        $source->addFacts($facts, (new Request())->setWebsite(new Website()), new Customer(), []);

        self::assertTrue($facts->isEmpty());
    }

    /**
     * @param RequestProductItem[] $items      what the item query returns
     * @param array<int, Price>    $listPrices item id => list price
     *
     * @return Fact[]
     */
    private function facts(array $items, array $listPrices, ?array $lastPaid): array
    {
        $listPriceSource = $this->createMock(ListPriceSource::class);
        $listPriceSource->expects(self::any())->method('getListPrices')->willReturn($listPrices);
        $lastPaidPriceSource = $this->createMock(LastPaidPriceSource::class);
        $lastPaidPriceSource->expects(self::any())->method('getLastPaidPrices')->willReturn($lastPaid ?? []);
        $source = $this->createSource($items, [ProductPrice::class], $listPriceSource, $lastPaidPriceSource);

        $facts = new FactSet();
        $source->addFacts(
            $facts,
            (new Request())->setWebsite(new Website()),
            null === $lastPaid ? null : new Customer(),
            null === $lastPaid ? null : []
        );

        return array_values(iterator_to_array($facts));
    }

    /**
     * @param RequestProductItem[] $items what the item query returns
     */
    private function createSource(
        array $items,
        array $viewableClasses,
        ListPriceSource $listPriceSource,
        LastPaidPriceSource $lastPaidPriceSource
    ): RfqItemSource {
        $query = $this->createMock(Query::class);
        $query->expects(self::any())->method('getResult')->willReturn($items);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $queryBuilder->expects(self::any())->method($method)->willReturnSelf();
        }
        $queryBuilder->expects(self::any())->method('getQuery')->willReturn($query);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())->method('createQueryBuilder')->willReturn($queryBuilder);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::any())->method('getManagerForClass')->willReturn($em);
        $accessProvider = $this->createMock(SummaryAccessProvider::class);
        $accessProvider->expects(self::any())
            ->method('canView')
            ->willReturnCallback(static fn (string $class) => \in_array($class, $viewableClasses, true));

        return new RfqItemSource($doctrine, $accessProvider, $listPriceSource, $lastPaidPriceSource);
    }

    /**
     * @param array<array{0: int, 1: string, 2: Price}> $prices product id, unit and the last paid price
     */
    private function lastPaid(array $prices): array
    {
        $lastPaid = [];
        foreach ($prices as [$productId, $unit, $price]) {
            $lastPaid[$productId][$unit][$price->getCurrency()] = $price;
        }

        return $lastPaid;
    }

    /**
     * @param Fact[] $facts
     *
     * @return array<string, Fact>
     */
    private function byKey(array $facts): array
    {
        return array_combine(array_map(static fn (Fact $fact) => $fact->key, $facts), $facts);
    }
}
