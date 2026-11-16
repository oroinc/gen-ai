<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider;

use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqSummaryData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Covers the RFQ provider against fixture RFQs.
 */
final class RfqSummaryFactProviderTest extends WebTestCase
{
    use QueryCountTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqSummaryData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testRegistryResolvesTheRfqProvider(): void
    {
        $provider = self::getContainer()->get('oro_smart_brief.tests.fact_provider.registry')
            ->getProvider(Request::class);

        self::assertSame(self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq'), $provider);
        self::assertInstanceOf(RfqSummaryFactProvider::class, $provider);
    }

    public function testRfqWithCustomer(): void
    {
        $values = $this->getValues(LoadRfqSummaryData::RFQ);

        self::assertSame(LoadRfqSummaryData::CUSTOMER_NAME, $values['company_name'] ?? null);
        // product-1 twice and product-2
        self::assertSame(2, $values['requested_product_count'] ?? null);
    }

    public function testGuestRfqHasNoCustomerScopedFacts(): void
    {
        $values = $this->getValues(LoadRfqSummaryData::GUEST_RFQ);

        // the guest's customer is named after the guest, so its name is person data; no customer-scoped
        // group runs for a guest
        foreach (['company_name', 'active_quote_count', 'quote_for_this_rfq', 'quote_for_this_rfq_status'] as $key) {
            self::assertArrayNotHasKey($key, $values);
        }
        self::assertSame(2, $values['requested_product_count'] ?? null);
    }

    public function testRfqWithoutWebsiteKeepsTheCustomerScopedFacts(): void
    {
        $values = $this->getValues(LoadRfqSummaryData::NO_WEBSITE_RFQ);

        self::assertSame(LoadRfqSummaryData::CUSTOMER_NAME, $values['company_name'] ?? null);
        self::assertSame(1, $values['requested_product_count'] ?? null);
    }

    public function testIncompleteLinesDoNotThrow(): void
    {
        // the line whose product was deleted does not count; the item without quantity does not matter here
        self::assertSame(1, $this->getValues(LoadRfqSummaryData::INCOMPLETE_RFQ)['requested_product_count'] ?? null);
    }

    public function testOnlyAllowedKeysAndNoPersonData(): void
    {
        // the facts allowed to reach the AI provider
        $patterns = array_map(
            static fn (string $key) => '/^' . preg_quote($key, '/') . '$/',
            [
                CustomerSummaryFactProvider::COMPANY_NAME,
                RfqSummaryFactProvider::ACTIVE_QUOTE_COUNT,
                RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ,
                RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ_STATUS,
                RfqSummaryFactProvider::REQUESTED_PRODUCT_COUNT,
                RfqSummaryFactProvider::PREVIOUSLY_PURCHASED_PRODUCT_COUNT,
                RfqSummaryFactProvider::LAST_PURCHASE_DATE,
                RfqSummaryFactProvider::LIST_GAP_PERCENT_RANGE,
                RfqSummaryFactProvider::LAST_PAID_GAP_PERCENT_RANGE,
            ]
        );
        foreach (
            [
                LoadRfqSummaryData::RFQ,
                LoadRfqSummaryData::GUEST_RFQ,
                LoadRfqSummaryData::NO_WEBSITE_RFQ,
                LoadRfqSummaryData::INCOMPLETE_RFQ,
                LoadRfqSummaryData::ONE_LINE_RFQ,
                LoadRfqSummaryData::FIFTY_LINE_RFQ,
            ] as $reference
        ) {
            foreach ($this->getFacts($reference) as $fact) {
                $declared = array_filter($patterns, static fn (string $pattern) => preg_match($pattern, $fact->key));
                self::assertNotEmpty($declared, sprintf('%s: %s is not an allowed key.', $reference, $fact->key));
                // non-scalar values (money) are checked in their encoded form
                $value = \is_scalar($fact->value) ? (string)$fact->value : json_encode($fact->value);
                foreach (LoadRfqSummaryData::PERSON_DATA as $personData) {
                    self::assertStringNotContainsString($personData, $value, $reference);
                }
            }
        }
    }

    public function testQueryCountDoesNotDependOnTheNumberOfLines(): void
    {
        $provider = $this->getProvider();
        $oneLine = $this->getReference(LoadRfqSummaryData::ONE_LINE_RFQ);
        $fiftyLines = $this->getReference(LoadRfqSummaryData::FIFTY_LINE_RFQ);
        // warm up metadata and ACL caches so only the provider's own queries are counted
        $provider->getFacts($oneLine);
        $provider->getFacts($fiftyLines);

        $oneLineCount = $this->countQueries(Request::class, static fn () => $provider->getFacts($oneLine));

        self::assertGreaterThan(0, $oneLineCount, 'The provider must query, or the comparison proves nothing.');
        self::assertSame(
            $oneLineCount,
            $this->countQueries(Request::class, static fn () => $provider->getFacts($fiftyLines))
        );
    }

    /**
     * @return array<string, mixed> fact values by key
     */
    private function getValues(string $reference): array
    {
        $values = [];
        foreach ($this->getFacts($reference) as $fact) {
            $values[$fact->key] = $fact->value;
        }

        return $values;
    }

    private function getFacts(string $reference): FactSet
    {
        return $this->getProvider()->getFacts($this->getReference($reference));
    }

    private function getProvider(): RfqSummaryFactProvider
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq');
    }
}
