<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Rfq;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Provider\Query\PurchasedOrderCriteria;

/**
 * The customer's last paid unit prices of the requested products: per product, unit, currency and price
 * type, the line item of the latest purchase ({@see PurchasedOrderCriteria}, the same rule as the purchase facts) that
 * the user can see. It starts from the latest purchase date per group that {@see PurchaseHistorySource} already
 * returned (a grouped `MAX`), and loads only the lines at those dates: one more query whatever the number of products
 * or purchases, under the user's ACL on orders, so a hidden newer purchase neither hides the
 * visible one nor reveals itself. At the same time the higher line id wins. This is a "grouped MAX joined back" in two
 * steps, because DQL cannot join a grouped derived table.
 *
 * Only a unit-priced line with a price is a comparable last paid price; a bundled-price line (a total) or a line
 * without a price gives none. Prices come from the raw money column; the caller passes the latest purchases only
 * when it may read orders and order lines.
 *
 * The result: the last price paid per product, unit and currency (no conversion).
 *
 * LastPaidPrices are keyed by product id, unit and currency.
 *
 * @phpstan-type LastPaidPrices array<int, array<string, array<string, Price>>>
 * @phpstan-import-type LatestPurchase from PurchaseHistorySource
 */
class LastPaidPriceSource
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly PurchasedOrderCriteria $purchasedOrderCriteria
    ) {
    }

    /**
     * @param int[]                $productIds      the products to price (the comparable items' products)
     * @param list<LatestPurchase> $latestPurchases from {@see PurchaseHistorySource::addFacts()}
     *
     * @return LastPaidPrices
     */
    public function getLastPaidPrices(Customer $customer, array $productIds, array $latestPurchases): array
    {
        $lastPaid = [];
        $productIds = array_values($productIds);
        $latest = $this->getLatestDates($latestPurchases, $productIds);
        if (!$latest) {
            return $lastPaid;
        }

        foreach ($this->getLatestLines($customer, $productIds, $latest) as $row) {
            if (OrderLineItem::PRICE_TYPE_UNIT === (int)$row['priceType'] && null !== $row['price']) {
                $lastPaid[(int)$row['productId']][(string)$row['unitCode']][(string)$row['currency']]
                    = Price::create((string)$row['price'], (string)$row['currency']);
            }
        }

        return $lastPaid;
    }

    /**
     * @param list<LatestPurchase> $latestPurchases
     *
     * @return array<string, string> group key => latest purchase date of the comparable groups (with a unit and a
     *     currency)
     */
    private function getLatestDates(array $latestPurchases, array $productIds): array
    {
        $latest = [];
        foreach ($latestPurchases as $group) {
            if (
                !\in_array($group['productId'], $productIds, true)
                || null === $group['unitCode']
                || null === $group['currency']
            ) {
                continue;
            }
            $latest[self::groupKey($group)] = $group['latestAt'];
        }

        return $latest;
    }

    /**
     * @param array<string, string> $latest
     *
     * @return array<string, array> group key => the line at the group's latest date (the highest id on a tie)
     */
    private function getLatestLines(Customer $customer, array $productIds, array $latest): array
    {
        $qb = $this->doctrine->getManagerForClass(Order::class)->createQueryBuilder()
            ->select(
                'IDENTITY(lineItem.product) AS productId',
                'IDENTITY(lineItem.productUnit) AS unitCode',
                'lineItem.currency AS currency',
                'lineItem.priceType AS priceType',
                'lineItem.value AS price',
                'o.createdAt AS createdAt'
            )
            ->from(Order::class, 'o')
            ->innerJoin('o.lineItems', 'lineItem')
            ->where('o.customer = :customer')
            ->andWhere('lineItem.product IN (:products)')
            ->andWhere('o.createdAt IN (:latestDates)')
            ->setParameter('customer', $customer)
            ->setParameter('products', $productIds)
            ->setParameter('latestDates', array_values(array_unique($latest)))
            ->orderBy('lineItem.id', 'ASC');
        $this->purchasedOrderCriteria->apply($qb, 'o');

        $selected = [];
        foreach ($this->aclHelper->apply($qb)->getArrayResult() as $row) {
            $key = self::groupKey($row);
            $createdAt = $row['createdAt'] instanceof \DateTimeInterface
                ? $row['createdAt']->format('Y-m-d H:i:s')
                : (string)$row['createdAt'];
            if (($latest[$key] ?? null) === $createdAt) {
                // ascending line ids: at the same time the higher id is selected last and wins
                $selected[$key] = $row;
            }
        }

        return $selected;
    }

    private static function groupKey(array $row): string
    {
        return implode("\0", [(int)$row['productId'], $row['unitCode'], $row['currency'], (int)$row['priceType']]);
    }
}
