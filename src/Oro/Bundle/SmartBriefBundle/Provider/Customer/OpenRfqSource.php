<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Customer;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\RFPBundle\Entity\Request;
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
 * The open RFQ facts: how many of the customer's RFQs await our quote — RFQs of the customer itself
 * in internal status `open` with no live quote of the same customer for them — and the date of the oldest
 * (user timezone). A linked quote that was moved to another customer does not count, as on the RFQ summary.
 * The count is always emitted, the date only when there is one. One query; the ACL applies to the RFQs and,
 * through the subquery, to the quotes, so a quote the user cannot see does not stop an RFQ from
 * counting. The query reads both RFQs and quotes, so nothing is read without class-level VIEW on both, and the
 * facts are gated together. With 1 to {@see FactSet::RECORD_LIMIT} RFQs, a second query under the same ACL reads
 * their ids, the records the block links to.
 */
class OpenRfqSource
{
    private const string OPEN_STATUS = 'open';

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
        if (
            !$this->accessProvider->canView(Request::class)
            || !$this->accessProvider->canView(Quote::class)
        ) {
            return;
        }
        $qb = $this->createOpenRfqQueryBuilder($customer);
        $row = $this->aclHelper->apply((clone $qb)->select('COUNT(r.id) AS rfqCount', 'MIN(r.createdAt) AS oldest'))
            ->getSingleResult();
        $count = (int)$row['rfqCount'];

        $facts->add(Fact::count(CustomerSummaryFactProvider::OPEN_RFQ_COUNT, $count));
        if ($count > 0 && null !== $row['oldest']) {
            $facts->add(new Fact(
                CustomerSummaryFactProvider::OLDEST_OPEN_RFQ_DATE,
                FactValueType::DATE,
                $this->calendarDateProvider->toDate(
                    new \DateTimeImmutable((string)$row['oldest'], new \DateTimeZone('UTC'))
                )
            ));
        }
        if ($count > 0 && $count <= FactSet::RECORD_LIMIT) {
            $facts->setRecords(CustomerSummaryFactProvider::OPEN_RFQ_COUNT, array_map(
                'intval',
                $this->aclHelper->apply($qb->select('r.id')->orderBy('r.id'))->getSingleColumnResult()
            ));
        }
    }

    private function createOpenRfqQueryBuilder(Customer $customer): QueryBuilder
    {
        $em = $this->doctrine->getManagerForClass(Request::class);
        $liveQuotes = $em->createQueryBuilder()
            ->select('q.id')
            ->from(Quote::class, 'q')
            ->where('q.request = r')
            ->andWhere('q.customer = r.customer');
        $qb = $em->createQueryBuilder()
            ->from(Request::class, 'r')
            ->where('r.customer = :customer')
            ->andWhere("JSON_EXTRACT(r.serialized_data, 'internal_status') = :open")
            ->setParameter('customer', $customer)
            ->setParameter('open', ExtendHelper::buildEnumOptionId(Request::INTERNAL_STATUS_CODE, self::OPEN_STATUS));
        $this->liveQuoteCriteria->apply($liveQuotes, 'q', $qb);
        $qb->andWhere($qb->expr()->not($qb->expr()->exists($liveQuotes->getDQL())));

        return $qb;
    }
}
