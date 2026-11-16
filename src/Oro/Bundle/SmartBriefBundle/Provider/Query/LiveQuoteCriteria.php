<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Component\DoctrineUtils\ORM\QueryBuilderUtil;

/**
 * The single definition of a "live quote", shared by the Customer and RFQ
 * summaries so both agree: internal status not deleted, cancelled, declined, expired or template (a quote without
 * status counts), the `expired` flag off, and `validUntil` empty or not earlier than now (a datetime, so a quote
 * that expired earlier today is not live).
 */
class LiveQuoteCriteria
{
    /** @var string[] internal ids of the statuses that end a quote's life */
    private const array ENDED_STATUSES = [
        Quote::INTERNAL_STATUS_DELETED,
        'cancelled',
        'declined',
        'expired',
        'template',
    ];

    /**
     * Adds the live-quote conditions for the quote alias to the query builder (WHERE or a subquery's WHERE).
     *
     * @param QueryBuilder      $qb              the query whose root (or joined) quote alias is filtered
     * @param string            $alias           the quote alias; a fixed identifier, it becomes part of the DQL
     * @param QueryBuilder|null $parameterHolder the query that receives the parameters; required when $qb is a
     *                                           subquery embedded as DQL, because its own parameters are lost
     */
    public function apply(QueryBuilder $qb, string $alias, ?QueryBuilder $parameterHolder = null): void
    {
        QueryBuilderUtil::checkIdentifier($alias);

        $holder = $parameterHolder ?? $qb;
        $ended = $alias . '_ended_statuses';
        $now = $alias . '_now';
        $status = sprintf("JSON_EXTRACT(%s.serialized_data, 'internal_status')", $alias);

        $qb->andWhere(sprintf('%1$s IS NULL OR %1$s NOT IN (:%2$s)', $status, $ended))
            ->andWhere(sprintf('%s.expired = false', $alias))
            ->andWhere(sprintf('%1$s.validUntil IS NULL OR %1$s.validUntil >= :%2$s', $alias, $now));
        $holder->setParameter($ended, array_map(
            static fn (string $id) => ExtendHelper::buildEnumOptionId(Quote::INTERNAL_STATUS_CODE, $id),
            self::ENDED_STATUSES
        ));
        // A mutable value with the mutable type: the one Oro maps to UTC (UTCDateTimeType), and the one DBAL 4 keeps.
        $holder->setParameter($now, new \DateTime('now', new \DateTimeZone('UTC')), Types::DATETIME_MUTABLE);
    }
}
