<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\OrderBundle\Provider\OrderStatusesProviderInterface;
use Oro\Component\DoctrineUtils\ORM\QueryBuilderUtil;

/**
 * The single definition of a "purchase", shared by the previous-purchase
 * facts and the last-paid prices so both agree: a top-level order (`parent` is null) in one of
 * the platform's "previously purchased" statuses (open, closed; a project that decorates
 * `oro_order.provider.previously_purchased_order_statuses` changes both), created within the purchase history
 * period ({@see getHistoryStart()}, the last 2 years) and no later than now (an order dated in the future, such as an
 * advance order of a recurring order, is not a purchase yet).
 *
 * The period keeps the purchase facts relevant (a price paid years ago is no comparison for a new quote) and bounds
 * the scan: the queries read only the customer's orders within it, not its whole history.
 *
 * Only the top-level order decides: in multi-shipping the parent order and its sub-orders share the line items,
 * so a purchase follows the parent's status, owner, organization and creation date, never a sub-order's. Draft
 * orders are left out by the platform's `order_draft` ORM filter, which is on outside the order edit routes.
 */
class PurchasedOrderCriteria
{
    public function __construct(
        private readonly OrderStatusesProviderInterface $purchasedStatusesProvider
    ) {
    }

    /**
     * Adds the purchase conditions for the order alias.
     *
     * @param QueryBuilder      $qb              the query whose order alias is filtered
     * @param string            $alias           the order alias; a fixed identifier, it becomes part of the DQL
     * @param QueryBuilder|null $parameterHolder the query that receives the parameters; required when $qb is a
     *                                           subquery embedded as DQL
     */
    public function apply(QueryBuilder $qb, string $alias, ?QueryBuilder $parameterHolder = null): void
    {
        QueryBuilderUtil::checkIdentifier($alias);

        $statuses = $alias . '_purchased_statuses';
        $since = $alias . '_since';
        $now = $alias . '_now';
        $qb->andWhere(sprintf('%s.parent IS NULL', $alias))
            ->andWhere(sprintf("JSON_EXTRACT(%s.serialized_data, 'internal_status') IN (:%s)", $alias, $statuses))
            ->andWhere(sprintf('%s.createdAt >= :%s', $alias, $since))
            ->andWhere(sprintf('%s.createdAt <= :%s', $alias, $now));
        $nowDate = new \DateTime('now', new \DateTimeZone('UTC'));
        ($parameterHolder ?? $qb)
            ->setParameter($statuses, $this->purchasedStatusesProvider->getAvailableStatuses())
            ->setParameter($since, $this->getHistoryStart($nowDate), Types::DATETIME_MUTABLE)
            ->setParameter($now, $nowDate, Types::DATETIME_MUTABLE);
    }

    /**
     * The start of the purchase history period: orders created earlier are not purchases for the facts.
     */
    protected function getHistoryStart(\DateTime $now): \DateTime
    {
        return (clone $now)->modify('-2 years');
    }
}
