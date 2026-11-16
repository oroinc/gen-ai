<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Tests\Functional\DataFixtures\LoadCustomers;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * The registry resolves Customer to the real provider service, the company name fact and that every emitted key
 * is declared, against fixture data.
 */
final class CustomerSummaryFactProviderTest extends WebTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomers::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testRegistryResolvesTheCustomerProvider(): void
    {
        $registry = self::getContainer()->get('oro_smart_brief.tests.fact_provider.registry');
        $provider = $registry->getProvider(Customer::class);

        // In the test container the real provider is wrapped by the switchable stub; it must delegate to it.
        self::assertSame(self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer'), $provider);
        self::assertInstanceOf(
            CustomerSummaryFactProvider::class,
            self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')
        );
        self::assertSame('customer', $provider->getEntityType());
        // Switched off, the stub must delegate, so every other suite sees the real provider.
        $facts = $provider->getFacts($this->getReference(LoadCustomers::CUSTOMER_LEVEL_1_1));
        $keys = array_map(static fn ($fact) => $fact->key, array_values(iterator_to_array($facts)));
        self::assertSame(['company_name', 'open_rfq_count', 'awaiting_quote_count'], $keys);
    }

    public function testFixtureCustomerFactsHoldTheName(): void
    {
        /** @var Customer $customer */
        $customer = $this->getReference(LoadCustomers::CUSTOMER_LEVEL_1_1);
        $provider = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real');

        $facts = $provider->getFacts($customer);

        $list = array_values(iterator_to_array($facts));
        $keys = array_map(static fn ($fact) => $fact->key, $list);
        self::assertSame(['company_name', 'open_rfq_count', 'awaiting_quote_count'], $keys);
        self::assertSame($customer->getName(), $list[0]->value);
        self::assertFalse($facts->hasInformativeFacts(), 'The name alone does not justify a summary.');
    }
}
