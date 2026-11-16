<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Customer;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The facts of the quotes awaiting the customer's reply: how many live quotes of the customer itself
 * are in internal status `sent_to_customer`, and the earliest `validUntil` among them (`MIN` ignores quotes
 * without one) as a calendar date in the user's timezone, no days-until fact. The count is always
 * emitted, the date only when one of them has a valid-until value. One query under the user's ACL; nothing is read
 * without class-level VIEW on Quote. With 1 to {@see FactSet::RECORD_LIMIT} quotes, a second query under the same
 * ACL reads their quote numbers, the records the block links to.
 */
class AwaitingQuoteSource
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly LiveQuoteCriteria $liveQuoteCriteria,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    public function addFacts(FactSet $facts, Customer $customer): void
    {
        if (!$this->accessProvider->canView(Quote::class)) {
            return;
        }
        $qb = $this->createAwaitingQuoteQueryBuilder($customer);
        $row = $this->aclHelper->apply((clone $qb)->select('COUNT(q.id) AS quoteCount', 'MIN(q.validUntil) AS nearest'))
            ->getSingleResult();
        $count = (int)$row['quoteCount'];

        $facts->add(Fact::count(CustomerSummaryFactProvider::AWAITING_QUOTE_COUNT, $count));
        if ($count > 0 && null !== $row['nearest']) {
            $facts->add(new Fact(
                CustomerSummaryFactProvider::NEAREST_QUOTE_EXPIRY_DATE,
                FactValueType::DATE,
                $this->calendarDateProvider->toDate(
                    new \DateTimeImmutable((string)$row['nearest'], new \DateTimeZone('UTC'))
                )
            ));
        }
        if ($count > 0 && $count <= FactSet::RECORD_LIMIT) {
            $facts->setRecords(CustomerSummaryFactProvider::AWAITING_QUOTE_COUNT, array_map(
                'strval',
                $this->aclHelper->apply($qb->select('q.qid')->orderBy('q.id'))->getSingleColumnResult()
            ));
        }
    }

    private function createAwaitingQuoteQueryBuilder(Customer $customer): QueryBuilder
    {
        $qb = $this->doctrine->getManagerForClass(Quote::class)->createQueryBuilder()
            ->from(Quote::class, 'q')
            ->where('q.customer = :customer')
            ->andWhere("JSON_EXTRACT(q.serialized_data, 'internal_status') = :sent")
            ->setParameter('customer', $customer)
            ->setParameter('sent', ExtendHelper::buildEnumOptionId(
                Quote::INTERNAL_STATUS_CODE,
                Quote::INTERNAL_STATUS_SENT_TO_CUSTOMER
            ));
        $this->liveQuoteCriteria->apply($qb, 'q');

        return $qb;
    }
}
