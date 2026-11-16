<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Rfq;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\PricingBundle\Model\ProductPriceCriteria;
use Oro\Bundle\PricingBundle\Model\ProductPriceScopeCriteriaFactoryInterface;
use Oro\Bundle\PricingBundle\Provider\ProductPriceProviderInterface;
use Oro\Bundle\RFPBundle\Entity\RequestProductItem;
use Oro\Bundle\WebsiteBundle\Entity\Website;

/**
 * The customer's list prices of the RFQ items: the matched price for product, unit, quantity and the
 * requested currency in the scope of the RFQ's website and customer, so quantity tiers apply. One
 * `getMatchedPrices()` call for all items on the ACL-aware price provider, which returns no price
 * without VIEW on product prices. The products and units are the items' own; no further entity is loaded.
 */
class ListPriceSource
{
    private const int MONEY_SCALE = 4;

    public function __construct(
        private readonly ProductPriceProviderInterface $productPriceProvider,
        private readonly ProductPriceScopeCriteriaFactoryInterface $scopeCriteriaFactory
    ) {
    }

    /**
     * @param RequestProductItem[] $items the comparable items (kits excluded by the caller)
     *
     * @return array<int, Price> item id => list price; items without a matched price are left out
     */
    public function getListPrices(array $items, Website $website, ?Customer $customer): array
    {
        if (!$items) {
            return [];
        }

        $criteria = [];
        foreach ($items as $item) {
            $criteria[$item->getId()] = new ProductPriceCriteria(
                $item->getRequestProduct()->getProduct(),
                $item->getProductUnit(),
                (float)$item->getQuantity(),
                $item->getCurrency()
            );
        }
        $matched = $this->productPriceProvider->getMatchedPrices(
            array_values($criteria),
            $this->scopeCriteriaFactory->create($website, $customer)
        );

        $prices = [];
        foreach ($criteria as $itemId => $itemCriteria) {
            $price = $matched[$itemCriteria->getIdentifier()] ?? null;
            if (null !== $price && null !== $price->getValue()) {
                // the price model holds a float; its string form is exact to the stored scale, normalized to the
                // four decimals of the money columns
                $amount = BigDecimal::of((string)$price->getValue())->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
                $prices[$itemId] = Price::create((string)$amount, (string)$price->getCurrency());
            }
        }

        return $prices;
    }
}
