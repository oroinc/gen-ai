<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\OpenRfqSource;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerRfqData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\FactValuesTrait;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Open RFQs of the customer itself
 * without a live quote, one rule per scenario customer, in one query; the count is always emitted, the oldest RFQ's
 * date (user timezone) only when there is one.
 */
final class OpenRfqSourceTest extends WebTestCase
{
    use FactValuesTrait;
    use QueryCountTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerRfqData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testOpenRfqsWithoutALiveQuote(): void
    {
        // no quote, declined quote, quote with the expired flag, quote past validUntil; the draft quote is live,
        // the processed RFQ and the child customer's RFQ do not count
        self::assertSame(
            ['open_rfq_count' => 4, 'oldest_open_rfq_date' => $this->toUtcUserDate('2026-07-01 10:00:00')],
            $this->getFacts(LoadCustomerRfqData::CUSTOMER)
        );
    }

    public function testTheRecordsAreTheCountedRfqs(): void
    {
        /** @var Customer $customer */
        $customer = $this->getReference(LoadCustomerRfqData::CUSTOMER);
        $facts = new FactSet();
        $this->getSource()->addFacts($facts, $customer);

        $ids = $facts->getRecords('open_rfq_count');
        self::assertCount(4, $ids);
        $rfqs = self::getContainer()->get('doctrine')->getRepository(Request::class)->findBy(['id' => $ids]);
        self::assertCount(4, $rfqs);
        foreach ($rfqs as $rfq) {
            self::assertSame($customer->getId(), $rfq->getCustomer()->getId());
            self::assertSame('open', $rfq->getInternalStatus()?->getInternalId());
        }
    }

    /**
     * @dataProvider scenarioDataProvider
     */
    public function testEachLiveQuoteRuleOnItsOwn(string $scenario, int $expectedCount): void
    {
        $facts = $this->getFacts(LoadCustomerRfqData::SCENARIO_PREFIX . $scenario);

        self::assertSame($expectedCount, $facts['open_rfq_count']);
        self::assertSame(
            $expectedCount > 0 ? $this->toUtcUserDate('2026-09-15 10:00:00') : null,
            $facts['oldest_open_rfq_date'] ?? null
        );
    }

    public static function scenarioDataProvider(): array
    {
        $data = [];
        foreach (LoadCustomerRfqData::SCENARIOS as $scenario => [, , $expectedCount]) {
            $data[$scenario] = [$scenario, $expectedCount];
        }

        return $data;
    }

    public function testCustomerWithoutRfqsCountsZero(): void
    {
        self::assertSame(['open_rfq_count' => 0], $this->getFacts(LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS));
    }

    /**
     * One query for the facts, and one more for the records to link to when there is something to count.
     */
    public function testQueryCountDoesNotDependOnTheNumberOfRfqsAndQuotes(): void
    {
        $source = $this->getSource();
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $this->getReference(LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS));

        $expected = [LoadCustomerRfqData::CUSTOMER => 2, LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS => 1];
        foreach ($expected as $reference => $queries) {
            $customer = $this->getReference($reference);
            self::assertSame(
                $queries,
                $this->countQueries(Request::class, static fn () => $source->addFacts(new FactSet(), $customer)),
                $reference
            );
        }
    }

    private function getFacts(string $customerReference): array
    {
        /** @var Customer $customer */
        $customer = $this->getReference($customerReference);

        return $this->getFactValues(fn (FactSet $facts) => $this->getSource()->addFacts($facts, $customer));
    }

    private function toUtcUserDate(string $utcTime): string
    {
        return $this->toUserDate(new \DateTimeImmutable($utcTime, new \DateTimeZone('UTC')));
    }

    private function getSource(): OpenRfqSource
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.open_rfq_source');
    }
}
