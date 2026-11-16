<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Rfq;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\PricingBundle\Model\ProductPriceCriteria;
use Oro\Bundle\PricingBundle\Model\ProductPriceScopeCriteriaFactoryInterface;
use Oro\Bundle\PricingBundle\Model\ProductPriceScopeCriteriaInterface;
use Oro\Bundle\PricingBundle\Provider\ProductPriceProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\ListPriceSource;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use PHPUnit\Framework\TestCase;

/**
 * One `getMatchedPrices()` call for all items, in the RFQ's scope, each
 * matched price mapped back to its item at the money scale; an item without a match has no list price.
 */
final class ListPriceSourceTest extends TestCase
{
    use RfqItemTestTrait;

    public function testOneLookupForAllItems(): void
    {
        $website = new Website();
        $customer = new Customer();
        $scope = $this->createMock(ProductPriceScopeCriteriaInterface::class);
        $scopeFactory = $this->createMock(ProductPriceScopeCriteriaFactoryInterface::class);
        $scopeFactory->expects(self::once())
            ->method('create')
            ->with(self::identicalTo($website), self::identicalTo($customer))
            ->willReturn($scope);
        $priceProvider = $this->createMock(ProductPriceProviderInterface::class);
        $priceProvider->expects(self::once())
            ->method('getMatchedPrices')
            ->with(self::countOf(3), self::identicalTo($scope))
            ->willReturnCallback(static function (array $criteria) {
                /** @var ProductPriceCriteria[] $criteria */
                return [$criteria[0]->getIdentifier() => Price::create(8.4, 'USD')];
            });

        $prices = (new ListPriceSource($priceProvider, $scopeFactory))->getListPrices(
            [
                $this->item(1, 11, 7, '9.10', 'USD', 'pc', 'A', '100'),
                $this->item(1, 12, 7, '9.20', 'USD', 'pc', 'A', '100'),
                $this->item(2, 13, 8, '1.00', 'USD', 'pc', 'B', '2.5'),
            ],
            $website,
            $customer
        );

        // the two identical criteria share the matched price
        self::assertEquals([11 => Price::create('8.4000', 'USD'), 12 => Price::create('8.4000', 'USD')], $prices);
    }

    public function testNoItemsNoLookup(): void
    {
        $priceProvider = $this->createMock(ProductPriceProviderInterface::class);
        $priceProvider->expects(self::never())->method('getMatchedPrices');

        self::assertSame([], (new ListPriceSource(
            $priceProvider,
            $this->createMock(ProductPriceScopeCriteriaFactoryInterface::class)
        ))->getListPrices([], new Website(), null));
    }
}
