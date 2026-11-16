<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Covers the live-quote definition shared by the quote and RFQ sources: the conditions, the ended statuses,
 * "now" as a UTC datetime, the parameter holder for subqueries and the alias guard.
 */
final class LiveQuoteCriteriaTest extends TestCase
{
    private LiveQuoteCriteria $criteria;

    #[\Override]
    protected function setUp(): void
    {
        $this->criteria = new LiveQuoteCriteria();
    }

    public function testConditionsAndParameters(): void
    {
        $qb = $this->createQueryBuilder();
        $before = time();

        $this->criteria->apply($qb, 'q');

        self::assertSame(
            'SELECT q.id FROM ' . Quote::class . ' q WHERE '
            . "(JSON_EXTRACT(q.serialized_data, 'internal_status') IS NULL"
            . " OR JSON_EXTRACT(q.serialized_data, 'internal_status') NOT IN (:q_ended_statuses))"
            . ' AND q.expired = false'
            . ' AND (q.validUntil IS NULL OR q.validUntil >= :q_now)',
            $qb->getDQL()
        );
        self::assertSame(
            [
                'quote_internal_status.deleted',
                'quote_internal_status.cancelled',
                'quote_internal_status.declined',
                'quote_internal_status.expired',
                'quote_internal_status.template',
            ],
            $qb->getParameter('q_ended_statuses')->getValue()
        );
        $now = $qb->getParameter('q_now');
        self::assertInstanceOf(\DateTime::class, $now->getValue());
        self::assertSame('UTC', $now->getValue()->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getValue()->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getValue()->getTimestamp());
        self::assertSame(Types::DATETIME_MUTABLE, $now->getType());
    }

    public function testSubqueryParametersGoToTheHolder(): void
    {
        $subquery = $this->createQueryBuilder();
        $parent = $this->createQueryBuilder();

        $this->criteria->apply($subquery, 'q', $parent);

        self::assertCount(0, $subquery->getParameters());
        self::assertNotNull($parent->getParameter('q_ended_statuses'));
        self::assertNotNull($parent->getParameter('q_now'));
    }

    /**
     * @dataProvider invalidAliasDataProvider
     */
    public function testInvalidAliasIsRejected(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->criteria->apply($this->createQueryBuilder(), $alias);
    }

    public static function invalidAliasDataProvider(): array
    {
        return [
            'expression' => ['q.id = 1 OR q'],
            'quote' => ["q'"],
            'space' => ['q x'],
        ];
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return (new QueryBuilder($this->createMock(EntityManagerInterface::class)))
            ->select('q.id')
            ->from(Quote::class, 'q');
    }
}
