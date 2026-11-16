<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Rfq;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\Query\PurchasedOrderCriteria;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The purchase facts: how many of the requested products the customer bought in the purchase history period
 * (always emitted) and when each was bought last: one date, or with several products bought last on different days
 * the range from the earliest to the latest of those dates (calendar dates in the user's timezone, no day-count
 * fact). A product
 * counts as purchased when a line item of it belongs to a purchase ({@see PurchasedOrderCriteria}) of the customer
 * itself (not its child customers); a product bought in several orders or lines counts once. Nothing is read
 * without class-level VIEW on Order and OrderLineItem: the query would report false zeros or false "not bought".
 *
 * The purchases are scanned once, whatever the number of products or orders: one query, under the user's ACL on
 * orders, reads each purchase with a requested product once per group (product, unit, currency, price type). From
 * its rows come:
 * - the latest purchase date per group, returned for the last paid prices;
 * - the numbers of the orders with a requested product, the records the block links to: at most
 *   {@see FactSet::RECORD_LIMIT}, and with more there are none.
 *
 * A LatestPurchase is the latest purchase date of a group (UTC, database format).
 *
 * @phpstan-type LatestPurchase array{productId: int, unitCode: ?string, currency: ?string, priceType: int,
 *     latestAt: string}
 */
class PurchaseHistorySource
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly PurchasedOrderCriteria $purchasedOrderCriteria,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    /**
     * @param int[] $productIds the requested products
     *
     * @return list<LatestPurchase>|null the latest purchase per group, or null when the purchases may not be read
     */
    public function addFacts(FactSet $facts, Customer $customer, array $productIds): ?array
    {
        if (
            !$this->accessProvider->canView(Order::class)
            || !$this->accessProvider->canView(OrderLineItem::class)
        ) {
            return null;
        }

        $purchases = $this->findPurchases($customer, $productIds);
        $latestPurchases = $this->getLatestPurchases($purchases);
        // the last purchase of each product, whatever the unit, currency or price type of the line
        $lastDates = [];
        foreach ($latestPurchases as $purchase) {
            $lastDates[$purchase['productId']] = max($lastDates[$purchase['productId']] ?? '', $purchase['latestAt']);
        }
        $facts->add(Fact::count(RfqSummaryFactProvider::PREVIOUSLY_PURCHASED_PRODUCT_COUNT, \count($lastDates)));
        if ($lastDates) {
            // one date, or the range from the earliest to the latest last purchase as an ISO 8601 interval
            $from = $this->toDate(min($lastDates));
            $to = $this->toDate(max($lastDates));
            $facts->add(new Fact(
                RfqSummaryFactProvider::LAST_PURCHASE_DATE,
                FactValueType::DATE,
                $from === $to ? $to : $from . FactValueType::RANGE_SEPARATOR . $to
            ));
            $orderNumbers = $this->getOrderNumbers($purchases);
            if (\count($orderNumbers) <= FactSet::RECORD_LIMIT) {
                $facts->setRecords(RfqSummaryFactProvider::PREVIOUSLY_PURCHASED_PRODUCT_COUNT, $orderNumbers);
            }
        }

        return $latestPurchases;
    }

    private function toDate(string $utcDateTime): string
    {
        return $this->calendarDateProvider->toDate(new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')));
    }

    /**
     * One row per purchase with a requested product and group: purchases within the purchase history period only
     * ({@see PurchasedOrderCriteria}), so the scan grows with the customer's recent orders, not with its whole
     * history.
     *
     * @param int[] $productIds
     *
     * @return list<array{orderId: int, identifier: ?string, createdAt: string, productId: int, unitCode: ?string,
     *     currency: ?string, priceType: int}> createdAt in UTC, database format
     */
    private function findPurchases(Customer $customer, array $productIds): array
    {
        if (!$productIds) {
            // an empty IN () list is invalid SQL, and nothing requested means nothing purchased
            return [];
        }

        $qb = $this->doctrine->getManagerForClass(Order::class)->createQueryBuilder()
            ->select(
                'o.id AS orderId',
                'o.identifier AS identifier',
                'MAX(o.createdAt) AS createdAt',
                'IDENTITY(lineItem.product) AS productId',
                'IDENTITY(lineItem.productUnit) AS unitCode',
                'lineItem.currency AS currency',
                'lineItem.priceType AS priceType'
            )
            ->from(Order::class, 'o')
            ->innerJoin('o.lineItems', 'lineItem')
            ->where('o.customer = :customer')
            ->andWhere('lineItem.product IN (:products)')
            // one row per order and group; MAX() of the order's own date reads it as a plain string, which is
            // cheaper to hydrate than a date-time per row
            ->groupBy('o.id, lineItem.product, lineItem.productUnit, lineItem.currency, lineItem.priceType')
            ->setParameter('customer', $customer)
            ->setParameter('products', array_values($productIds));
        $this->purchasedOrderCriteria->apply($qb, 'o');

        return array_map(
            static fn (array $row) => [
                'orderId' => (int)$row['orderId'],
                'identifier' => null !== $row['identifier'] ? (string)$row['identifier'] : null,
                'createdAt' => (string)$row['createdAt'],
                'productId' => (int)$row['productId'],
                'unitCode' => null !== $row['unitCode'] ? (string)$row['unitCode'] : null,
                'currency' => null !== $row['currency'] ? (string)$row['currency'] : null,
                'priceType' => (int)$row['priceType'],
            ],
            $this->aclHelper->apply($qb)->getArrayResult()
        );
    }

    /**
     * @param list<array> $purchases from {@see findPurchases()}
     *
     * @return list<LatestPurchase> the latest purchase date per group
     */
    private function getLatestPurchases(array $purchases): array
    {
        $latest = [];
        foreach ($purchases as $purchase) {
            $key = implode("\0", [
                $purchase['productId'],
                $purchase['unitCode'],
                $purchase['currency'],
                $purchase['priceType'],
            ]);
            if (!isset($latest[$key]) || $purchase['createdAt'] > $latest[$key]['latestAt']) {
                $latest[$key] = [
                    'productId' => $purchase['productId'],
                    'unitCode' => $purchase['unitCode'],
                    'currency' => $purchase['currency'],
                    'priceType' => $purchase['priceType'],
                    'latestAt' => $purchase['createdAt'],
                ];
            }
        }

        return array_values($latest);
    }

    /**
     * @param list<array> $purchases from {@see findPurchases()}
     *
     * @return list<string> the numbers of the orders with a requested product, newest first; orders without a number
     *     cannot be linked and are left out
     */
    private function getOrderNumbers(array $purchases): array
    {
        $orders = [];
        foreach ($purchases as $purchase) {
            if (null !== $purchase['identifier'] && '' !== trim($purchase['identifier'])) {
                $orders[$purchase['orderId']] = $purchase;
            }
        }
        // newest first; at the same time the higher id first
        uasort($orders, static fn (array $a, array $b) => [$b['createdAt'], $b['orderId']]
            <=> [$a['createdAt'], $a['orderId']]);

        return array_values(array_column($orders, 'identifier'));
    }
}
