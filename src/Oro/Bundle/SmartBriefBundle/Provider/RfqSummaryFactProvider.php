<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\PurchaseHistorySource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqItemSource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqQuoteSource;

/**
 * Facts for RFQ summaries. The subject is one RFQ; its customer is `Request::getCustomer()` and its website
 * `Request::getWebsite()`.
 *
 * Fact                                  | Changes when
 * --------------------------------------|---------------------------------------------------------------
 * company_name                          | the RFQ's customer changes or is renamed
 * active_quote_count,                   | a quote of the customer is created, sent, accepted, declined,
 *   quote_for_this_rfq,                 |   cancelled, expires or passes its valid-until; a quote is
 *   quote_for_this_rfq_status           |   linked to this RFQ
 * requested_product_count               | an RFQ line is added or removed, or its product changes
 * previously_purchased_product_count,   | an order of the customer containing a requested product is
 *   last_purchase_date                  |   created, changes status or is edited; the RFQ's products change
 * list_gap_percent_range,               | an RFQ line's product, price, unit or quantity changes; the
 *   last_paid_gap_percent_range         |   combined price lists are rebuilt; a matching order is placed or
 *                                       |   changes status (each only when a requested quantity has it)
 *
 * Rules every fact group follows:
 *   - customer-scoped groups (company name, quotes, purchases, last-paid prices) run only when the RFQ has a
 *     customer that is not a guest ({@see getCustomer()}), and the list-price part only when it has a
 *     website; `requested_product_count` is always emitted;
 *   - class-level VIEW first, through {@see SummaryAccessProvider}; then every query applies the ACL,
 *     and list prices come from the ACL-aware price provider;
 *   - raw typed values only; missing data skips the fact or the item, never an exception; the
 *     query count does not depend on the number of RFQ lines;
 *   - only the keys listed above are emitted: no requester name, email, phone,
 *     note, PO number or comment.
 *
 * @phpstan-import-type LatestPurchase from PurchaseHistorySource
 */
class RfqSummaryFactProvider implements SummaryFactProviderInterface
{
    public const string ENTITY_TYPE = 'rfq';

    public const string ACTIVE_QUOTE_COUNT = 'active_quote_count';
    public const string QUOTE_FOR_THIS_RFQ = 'quote_for_this_rfq';
    public const string QUOTE_FOR_THIS_RFQ_STATUS = 'quote_for_this_rfq_status';
    public const string REQUESTED_PRODUCT_COUNT = 'requested_product_count';
    public const string PREVIOUSLY_PURCHASED_PRODUCT_COUNT = 'previously_purchased_product_count';
    public const string LAST_PURCHASE_DATE = 'last_purchase_date';
    public const string LIST_GAP_PERCENT_RANGE = 'list_gap_percent_range';
    public const string LAST_PAID_GAP_PERCENT_RANGE = 'last_paid_gap_percent_range';

    public function __construct(
        private readonly RfqQuoteSource $quoteSource,
        private readonly PurchaseHistorySource $purchaseHistorySource,
        private readonly RfqItemSource $itemSource
    ) {
    }

    #[\Override]
    public function supportsClass(string $entityClass): bool
    {
        // Exact match: decided from the name alone, without autoloading a class string from the route.
        return Request::class === $entityClass;
    }

    #[\Override]
    public function getEntityType(): string
    {
        return self::ENTITY_TYPE;
    }

    #[\Override]
    public function getEnabledConfigKey(): string
    {
        return Configuration::getConfigKeyByName(Configuration::RFQ_ENABLED_KEY);
    }

    /**
     * @param Request $entity
     */
    #[\Override]
    public function getFacts(object $entity): FactSet
    {
        $facts = new FactSet();
        $customer = $this->getCustomer($entity);
        $productIds = $this->getRequestedProductIds($entity);
        if (null !== $customer) {
            $this->addCompanyName($facts, $customer);
            $this->quoteSource->addFacts($facts, $entity, $customer);
        }
        $this->addRequestedProductCount($facts, $productIds);
        $latestPurchases = null !== $customer
            ? $this->purchaseHistorySource->addFacts($facts, $customer, $productIds)
            : null;
        $this->itemSource->addFacts($facts, $entity, $customer, $latestPurchases);

        return $facts;
    }

    /**
     * The RFQ's customer for the customer-scoped facts, or null for an RFQ without one. A guest RFQ has
     * a customer too: the storefront creates it for the guest customer user and names it after the guest. It is
     * treated as no customer, so no person's name and no guest's history reach the facts.
     */
    private function getCustomer(Request $request): ?Customer
    {
        return $request->getCustomerUser()?->isGuest() ? null : $request->getCustomer();
    }

    /**
     * The RFQ customer's name, as in {@see CustomerSummaryFactProvider}.
     */
    private function addCompanyName(FactSet $facts, Customer $customer): void
    {
        $name = (string)$customer->getName();
        if ('' !== trim($name)) {
            $facts->add(new Fact(CustomerSummaryFactProvider::COMPANY_NAME, FactValueType::TEXT, $name, false));
        }
    }

    /**
     * The distinct products of the RFQ's lines; a line without a product (deleted) is not a requested product.
     *
     * @return int[]
     */
    private function getRequestedProductIds(Request $request): array
    {
        $productIds = [];
        foreach ($request->getRequestProducts() as $requestProduct) {
            $productId = $requestProduct->getProduct()?->getId();
            if (null !== $productId) {
                $productIds[$productId] = $productId;
            }
        }

        return array_values($productIds);
    }

    /**
     * The number of distinct requested products, always emitted.
     *
     * @param int[] $productIds
     */
    private function addRequestedProductCount(FactSet $facts, array $productIds): void
    {
        $facts->add(Fact::count(self::REQUESTED_PRODUCT_COUNT, \count($productIds)));
    }
}
