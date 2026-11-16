<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Customer;

use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Provider\OrderStatusesProviderInterface;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The last order facts: the date (user timezone) and total of the customer's newest top-level order
 * (sub-orders excluded) of the customer itself (child customers excluded) whose internal status is not
 * `cancelled` — orders without a status count. Nothing is read without class-level VIEW on Order.
 *
 * - One query with the ACL applied for the current user, whatever the number of orders. For a
 *   single subject the newest row is read with ORDER BY + LIMIT 1 and a deterministic id tie-break; a grouped
 *   MAX() joined back is only needed for per-item lookups.
 * - Draft orders are excluded by the platform's `DraftSessionOrmFilter`, which is enabled outside the order edit
 *   pages.
 * - The total is the raw `totalValue` column (a decimal string, never a float) in its own `totalCurrency` (the
 *   order currency when that is empty); it is left out when the stored total or currency is missing.
 * - The order number is the record the block links to.
 */
class LastOrderSource
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    public function addFacts(FactSet $facts, Customer $customer): void
    {
        if (!$this->accessProvider->canView(Order::class)) {
            return;
        }
        $row = $this->findLastOrder($customer);
        if (null === $row || !$row['createdAt'] instanceof \DateTimeInterface) {
            return;
        }

        $facts->add(new Fact(
            CustomerSummaryFactProvider::LAST_ORDER_DATE,
            FactValueType::DATE,
            $this->calendarDateProvider->toDate($row['createdAt'])
        ));
        if ('' !== trim((string)$row['identifier'])) {
            $facts->setRecords(CustomerSummaryFactProvider::LAST_ORDER_DATE, [(string)$row['identifier']]);
        }
        if (null !== $row['total'] && $row['currency']) {
            $facts->add(new Fact(
                CustomerSummaryFactProvider::LAST_ORDER_TOTAL,
                FactValueType::MONEY,
                Price::create((string)$row['total'], (string)$row['currency'])
            ));
        }
    }

    /**
     * @return array{createdAt: mixed, total: mixed, currency: mixed}|null
     */
    private function findLastOrder(Customer $customer): ?array
    {
        $statusExpr = "JSON_EXTRACT(o.serialized_data, 'internal_status')";
        $qb = $this->doctrine->getManagerForClass(Order::class)
            ->createQueryBuilder()
            ->select(
                'o.createdAt',
                'o.identifier AS identifier',
                'o.totalValue AS total',
                'COALESCE(o.totalCurrency, o.currency) AS currency'
            )
            ->from(Order::class, 'o')
            ->where('o.customer = :customer')
            ->andWhere('o.parent IS NULL')
            // an order dated in the future, such as an advance order of a recurring order, is not placed yet
            ->andWhere('o.createdAt <= :now')
            // `<>` alone would drop the orders without a status
            ->andWhere(sprintf('%1$s IS NULL OR %1$s <> :cancelled', $statusExpr))
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('customer', $customer)
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')), Types::DATETIME_MUTABLE)
            ->setParameter('cancelled', ExtendHelper::buildEnumOptionId(
                Order::INTERNAL_STATUS_CODE,
                OrderStatusesProviderInterface::INTERNAL_STATUS_CANCELLED
            ));

        return $this->aclHelper->apply($qb)->getOneOrNullResult();
    }
}
