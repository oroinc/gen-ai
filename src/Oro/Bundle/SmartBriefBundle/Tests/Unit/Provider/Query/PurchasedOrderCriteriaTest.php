<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Provider\OrderStatusesProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\Query\PurchasedOrderCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shared "purchased" definition of the RFQ purchase and item facts: top-level orders in the platform's
 * previously purchased statuses, created within the purchase history period and not in the future, the parameter
 * holder for subqueries and the alias guard.
 */
final class PurchasedOrderCriteriaTest extends TestCase
{
    private const array STATUSES = ['order_internal_status.open', 'order_internal_status.closed'];

    private PurchasedOrderCriteria $criteria;

    #[\Override]
    protected function setUp(): void
    {
        $statusesProvider = $this->createMock(OrderStatusesProviderInterface::class);
        $statusesProvider->expects(self::any())->method('getAvailableStatuses')->willReturn(self::STATUSES);
        $this->criteria = new PurchasedOrderCriteria($statusesProvider);
    }

    public function testConditionsAndParameters(): void
    {
        $qb = $this->createQueryBuilder();

        $this->criteria->apply($qb, 'o');

        self::assertSame(
            'SELECT o.id FROM ' . Order::class . ' o WHERE o.parent IS NULL'
            . " AND JSON_EXTRACT(o.serialized_data, 'internal_status') IN (:o_purchased_statuses)"
            . ' AND o.createdAt >= :o_since'
            . ' AND o.createdAt <= :o_now',
            $qb->getDQL()
        );
        self::assertSame(self::STATUSES, $qb->getParameter('o_purchased_statuses')->getValue());
        $now = $qb->getParameter('o_now')->getValue();
        self::assertInstanceOf(\DateTime::class, $now);
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 5, 'Orders dated in the future are left out.');
        $since = $qb->getParameter('o_since')->getValue();
        self::assertInstanceOf(\DateTime::class, $since);
        self::assertEquals((clone $now)->modify('-2 years'), $since, 'Orders older than 2 years are left out.');
        self::assertSame('UTC', $since->getTimezone()->getName());
    }

    public function testHistoryPeriodCanBeChangedByAProject(): void
    {
        $statusesProvider = $this->createMock(OrderStatusesProviderInterface::class);
        $statusesProvider->expects(self::any())->method('getAvailableStatuses')->willReturn(self::STATUSES);
        $criteria = new class ($statusesProvider) extends PurchasedOrderCriteria {
            #[\Override]
            protected function getHistoryStart(\DateTime $now): \DateTime
            {
                return (clone $now)->modify('-6 months');
            }
        };
        $qb = $this->createQueryBuilder();

        $criteria->apply($qb, 'o');

        self::assertEquals(
            (clone $qb->getParameter('o_now')->getValue())->modify('-6 months'),
            $qb->getParameter('o_since')->getValue()
        );
    }

    public function testSubqueryParametersGoToTheHolder(): void
    {
        $subquery = $this->createQueryBuilder();
        $parent = $this->createQueryBuilder();

        $this->criteria->apply($subquery, 'o', $parent);

        self::assertCount(0, $subquery->getParameters());
        self::assertNotNull($parent->getParameter('o_purchased_statuses'));
        self::assertNotNull($parent->getParameter('o_since'));
        self::assertNotNull($parent->getParameter('o_now'));
    }

    public function testInvalidAliasIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->criteria->apply($this->createQueryBuilder(), 'o.id = 1 OR o');
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return (new QueryBuilder($this->createMock(EntityManagerInterface::class)))
            ->select('o.id')
            ->from(Order::class, 'o');
    }
}
