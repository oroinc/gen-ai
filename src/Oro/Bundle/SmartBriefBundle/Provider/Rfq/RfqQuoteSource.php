<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Rfq;

use Doctrine\ORM\EntityManagerInterface;
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
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * Quote facts of an RFQ, on live quotes (the shared {@see LiveQuoteCriteria}) of the RFQ's customer:
 * how many are not accepted yet (always emitted), and the most recently created one whose `request` is this RFQ
 * (accepted included; a quote of another customer is ignored) — its QID as text and its internal status as the
 * full enum option id. Two queries whatever the number of quotes, both under the user's ACL;
 * nothing is read without class-level VIEW on Quote. The QIDs are the records the block links to: the quote for this
 * RFQ, and the active quotes when there are 1 to {@see FactSet::RECORD_LIMIT} of them (a third query).
 */
class RfqQuoteSource
{
    private const string ACCEPTED_STATUS = 'accepted';
    private const string STATUS_EXPRESSION = "JSON_EXTRACT(q.serialized_data, 'internal_status')";

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly LiveQuoteCriteria $liveQuoteCriteria,
        private readonly SummaryAccessProvider $accessProvider
    ) {
    }

    public function addFacts(FactSet $facts, Request $request, Customer $customer): void
    {
        if (!$this->accessProvider->canView(Quote::class)) {
            return;
        }

        $activeQuotes = $this->createActiveQuoteQueryBuilder($customer);
        $count = (int)$this->aclHelper->apply((clone $activeQuotes)->select('COUNT(q.id)'))->getSingleScalarResult();
        $facts->add(Fact::count(RfqSummaryFactProvider::ACTIVE_QUOTE_COUNT, $count));
        if ($count > 0 && $count <= FactSet::RECORD_LIMIT) {
            $facts->setRecords(RfqSummaryFactProvider::ACTIVE_QUOTE_COUNT, array_map(
                'strval',
                $this->aclHelper->apply($activeQuotes->select('q.qid')->orderBy('q.id'))->getSingleColumnResult()
            ));
        }
        $quote = $this->findQuoteForRfq($request, $customer);
        if (null === $quote) {
            return;
        }
        $facts->add(new Fact(RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ, FactValueType::TEXT, (string)$quote['qid']));
        $facts->setRecords(RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ, [(string)$quote['qid']]);
        if (null !== $quote['internalStatus']) {
            $facts->add(new Fact(
                RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ_STATUS,
                FactValueType::ENUM_OPTION,
                (string)$quote['internalStatus']
            ));
        }
    }

    private function createActiveQuoteQueryBuilder(Customer $customer): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->from(Quote::class, 'q')
            ->where('q.customer = :customer')
            ->andWhere(sprintf('%1$s IS NULL OR %1$s <> :accepted', self::STATUS_EXPRESSION))
            ->setParameter('customer', $customer)
            ->setParameter(
                'accepted',
                ExtendHelper::buildEnumOptionId(Quote::INTERNAL_STATUS_CODE, self::ACCEPTED_STATUS)
            );
        $this->liveQuoteCriteria->apply($qb, 'q');

        return $qb;
    }

    /**
     * A linked quote of another customer is ignored, as in `OpenRfqSource`.
     *
     * The QID is free text (import, a crafted form post), so up to 200 sanitized characters of back-office text can
     * reach the AI; any non-blank QID is sent.
     *
     * @return array{qid: mixed, internalStatus: mixed}|null
     */
    private function findQuoteForRfq(Request $request, Customer $customer): ?array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('q.qid AS qid', self::STATUS_EXPRESSION . ' AS internalStatus')
            ->from(Quote::class, 'q')
            ->where('q.request = :request')
            ->andWhere('q.customer = :customer')
            ->setParameter('request', $request)
            ->setParameter('customer', $customer)
            ->orderBy('q.createdAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setMaxResults(1);
        $this->liveQuoteCriteria->apply($qb, 'q');

        $row = $this->aclHelper->apply($qb)->getOneOrNullResult();
        // The QID is how the user identifies the quote. After a flush every quote has one (QuoteListener fills
        // it from the id), so this guards only a blank QID.
        if (null === $row || '' === trim((string)$row['qid'])) {
            return null;
        }

        return $row;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->doctrine->getManagerForClass(Quote::class);
    }
}
