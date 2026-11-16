<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\AwaitingQuoteSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastContactSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastOrderSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\OpenRfqSource;

/**
 * Facts for Customer summaries. The subject is the customer itself; child customers are not included.
 *
 * Fact                          | Changes when
 * ------------------------------|-----------------------------------------------------------
 * company_name                  | the customer is renamed
 * last_order_*                  | a top-level order is created, edited or cancelled
 * open_rfq_count,               | an RFQ is created or changes internal status;
 *   oldest_open_rfq_date        |   a quote is linked or changes status
 * awaiting_quote_count,         | a quote is sent, accepted, declined or expires
 *   nearest_quote_expiry_date   |
 * last_contact_*                | an email, call or conversation is linked to the customer or its users
 *
 * Each source adds its own facts; the rules every source follows:
 *   - class-level VIEW first, through {@see SummaryAccessProvider}: every class the source reads is
 *     checked (the open RFQ facts read both `Request` and `Quote`), with its ACL class (`EmailUser`, not `Email`), and
 *     all facts of the source are gated together, so no count leaks while the dates are skipped; the last contact
 *     source queries only the activity classes the user may view;
 *   - then every query applies `AclHelper::apply()`, so the facts describe the records the user sees;
 *   - raw typed values only; missing data skips the fact, never a placeholder, never an exception;
 *     the query count does not depend on the number of related records;
 *   - only the keys listed above are emitted.
 */
class CustomerSummaryFactProvider implements SummaryFactProviderInterface
{
    public const string ENTITY_TYPE = 'customer';

    public const string COMPANY_NAME = 'company_name';
    public const string LAST_ORDER_DATE = 'last_order_date';
    public const string LAST_ORDER_TOTAL = 'last_order_total';
    public const string OPEN_RFQ_COUNT = 'open_rfq_count';
    public const string OLDEST_OPEN_RFQ_DATE = 'oldest_open_rfq_date';
    public const string AWAITING_QUOTE_COUNT = 'awaiting_quote_count';
    public const string NEAREST_QUOTE_EXPIRY_DATE = 'nearest_quote_expiry_date';
    public const string LAST_CONTACT_DATE = 'last_contact_date';
    public const string LAST_CONTACT_CHANNEL = 'last_contact_channel';

    public function __construct(
        private readonly LastOrderSource $lastOrderSource,
        private readonly OpenRfqSource $openRfqSource,
        private readonly AwaitingQuoteSource $awaitingQuoteSource,
        private readonly LastContactSource $lastContactSource
    ) {
    }

    #[\Override]
    public function supportsClass(string $entityClass): bool
    {
        // Exact match: decided from the name alone, without autoloading a class string from the route.
        return Customer::class === $entityClass;
    }

    #[\Override]
    public function getEntityType(): string
    {
        return self::ENTITY_TYPE;
    }

    #[\Override]
    public function getEnabledConfigKey(): string
    {
        return Configuration::getConfigKeyByName(Configuration::CUSTOMER_ENABLED_KEY);
    }

    /**
     * @param Customer $entity
     */
    #[\Override]
    public function getFacts(object $entity): FactSet
    {
        $facts = new FactSet();
        $this->addCompanyName($facts, $entity);
        $this->lastOrderSource->addFacts($facts, $entity);
        $this->openRfqSource->addFacts($facts, $entity);
        $this->awaitingQuoteSource->addFacts($facts, $entity);
        $this->lastContactSource->addFacts($facts, $entity);

        return $facts;
    }

    private function addCompanyName(FactSet $facts, Customer $customer): void
    {
        $name = (string)$customer->getName();
        if ('' !== trim($name)) {
            $facts->add(new Fact(self::COMPANY_NAME, FactValueType::TEXT, $name, false));
        }
    }
}
