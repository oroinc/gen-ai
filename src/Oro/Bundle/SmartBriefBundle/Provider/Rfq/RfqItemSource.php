<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Rfq;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\PricingBundle\Entity\ProductPrice;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\RFPBundle\Entity\RequestProductItem;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The price comparison of an RFQ: how its requested prices compare with the list prices and with the prices the
 * customer last paid, as two ranges of percentage differences over all requested quantities, from the lowest to the
 * highest. Each range is a fact only when at least one quantity has that difference, so the AI never gets a range it
 * could mistake for another (no list prices, no list-price range). The items themselves are not facts: the brief
 * summarizes across them, and with the items the AI listed them or worked out ranges of its own.
 *
 * - The items are the RFQ's complete ones, loaded in one query with their request product and product: items whose
 *   request product has a product and that have a requested price, a non-empty currency, a unit and a non-negative
 *   quantity. A product-kit item is not compared (a plain price lookup ignores the kit configuration). The RFQ itself
 *   was already checked for VIEW by the caller.
 * - List prices are read only with a website and VIEW on product prices, in the scope of the RFQ's own customer even
 *   for a guest RFQ, so a guest is priced through its anonymous customer group as on the storefront. A list price
 *   depends on the quantity (price tiers), so it is matched per quantity. Last paid prices only when the caller
 *   passes the customer's latest purchases (a non-guest customer whose orders the user may read), for the same
 *   product, unit and currency.
 * - A difference is the percentage `(requested − reference) / reference × 100` in the reference currency (never
 *   across currencies), rounded once to one decimal, half up (none for a zero reference). The arithmetic is decimal.
 * - A range is `lowest/highest`, or the value alone when both are equal.
 *
 * @phpstan-import-type LastPaidPrices from LastPaidPriceSource
 * @phpstan-import-type LatestPurchase from PurchaseHistorySource
 */
class RfqItemSource
{
    private const int PERCENT_SCALE = 1;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly ListPriceSource $listPriceSource,
        private readonly LastPaidPriceSource $lastPaidPriceSource
    ) {
    }

    /**
     * @param Customer|null             $customer        the RFQ's customer for the purchases; null for a guest
     * @param list<LatestPurchase>|null $latestPurchases the customer's latest purchases, or null when they may not
     *                                                   be read
     */
    public function addFacts(FactSet $facts, Request $request, ?Customer $customer, ?array $latestPurchases): void
    {
        $items = array_values(array_filter(
            $this->getItems($request),
            static fn (RequestProductItem $item) => !$item->getRequestProduct()->getProduct()->isKit()
        ));
        if (!$items) {
            return;
        }

        $listPrices = $this->getListPrices($request, $items);
        $lastPaid = null !== $customer && null !== $latestPurchases
            ? $this->getLastPaidPrices($customer, $items, $latestPurchases)
            : null;

        $listPercents = [];
        $lastPaidPercents = [];
        foreach ($items as $item) {
            $requestedPrice = $item->getPrice();
            $listPrice = $listPrices[$item->getId()] ?? null;
            // never compare across currencies
            if (null !== $listPrice && $listPrice->getCurrency() === $requestedPrice->getCurrency()) {
                $listPercents[] = $this->getGapPercent($requestedPrice, $listPrice);
            }
            // the same product, unit and currency only (no conversion)
            $productId = $item->getRequestProduct()->getProduct()->getId();
            $lastPaidPrice = $lastPaid[$productId][$item->getProductUnitCode()][$requestedPrice->getCurrency()] ?? null;
            if (null !== $lastPaidPrice) {
                $lastPaidPercents[] = $this->getGapPercent($requestedPrice, $lastPaidPrice);
            }
        }

        $this->addRange($facts, RfqSummaryFactProvider::LIST_GAP_PERCENT_RANGE, $listPercents);
        $this->addRange($facts, RfqSummaryFactProvider::LAST_PAID_GAP_PERCENT_RANGE, $lastPaidPercents);
    }

    /**
     * @param RequestProductItem[] $items
     *
     * @return array<int, Price> item id => list price
     */
    private function getListPrices(Request $request, array $items): array
    {
        $website = $request->getWebsite();
        // the price provider checks this itself only for an authenticated back-office user; check it here too
        if (null === $website || !$this->accessProvider->canView(ProductPrice::class)) {
            return [];
        }

        return $this->listPriceSource->getListPrices($items, $website, $request->getCustomer());
    }

    /**
     * @param RequestProductItem[]  $items
     * @param list<LatestPurchase> $latestPurchases
     *
     * @return LastPaidPrices
     */
    private function getLastPaidPrices(Customer $customer, array $items, array $latestPurchases): array
    {
        return $this->lastPaidPriceSource->getLastPaidPrices(
            $customer,
            array_values(array_unique(array_map(
                static fn (RequestProductItem $item) => $item->getRequestProduct()->getProduct()->getId(),
                $items
            ))),
            $latestPurchases
        );
    }

    /**
     * @return RequestProductItem[]
     */
    private function getItems(Request $request): array
    {
        // one query for the items with their request products and products, instead of lazy loading them per line
        return $this->doctrine->getManagerForClass(RequestProductItem::class)->createQueryBuilder()
            ->select('item', 'requestProduct', 'product')
            ->from(RequestProductItem::class, 'item')
            ->innerJoin('item.requestProduct', 'requestProduct')
            ->innerJoin('requestProduct.product', 'product')
            ->where('requestProduct.request = :request')
            ->andWhere('item.value IS NOT NULL')
            ->andWhere("item.currency IS NOT NULL AND item.currency <> ''")
            ->andWhere('item.productUnit IS NOT NULL')
            ->andWhere('item.quantity IS NOT NULL AND item.quantity >= 0')
            ->setParameter('request', $request)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string|null> $percents
     */
    private function addRange(FactSet $facts, string $key, array $percents): void
    {
        $decimals = array_map(
            static fn (string $percent) => BigDecimal::of($percent),
            array_filter($percents, static fn (?string $percent) => null !== $percent)
        );
        if (!$decimals) {
            return;
        }
        $lowest = BigDecimal::min(...$decimals);
        $highest = BigDecimal::max(...$decimals);
        $facts->add(new Fact(
            $key,
            FactValueType::PERCENT,
            $lowest->isEqualTo($highest) ? (string)$lowest : $lowest . FactValueType::RANGE_SEPARATOR . $highest
        ));
    }

    /**
     * `(requested − reference) / reference × 100`, rounded once to one decimal, half up (Brick rounds the exact
     * quotient, half away from zero); none for a zero reference.
     */
    private function getGapPercent(Price $requested, Price $reference): ?string
    {
        $referenceAmount = BigDecimal::of((string)$reference->getValue());
        if ($referenceAmount->isZero()) {
            return null;
        }

        return (string)BigDecimal::of((string)$requested->getValue())
            ->minus($referenceAmount)
            ->multipliedBy(100)
            ->dividedBy($referenceAmount, self::PERCENT_SCALE, RoundingMode::HALF_UP);
    }
}
