<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\AwaitingQuoteSource;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerRfqData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\FactValuesTrait;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Live quotes sent to the customer itself, one rule per scenario
 * customer, the earliest validUntil as a date in the user's timezone, in one query; the count is always emitted,
 * the date only when there is one.
 */
final class AwaitingQuoteSourceTest extends WebTestCase
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

    public function testLiveSentQuotesOfTheCustomerItself(): void
    {
        // valid until 2099-12-31, 2099-06-30 and without validUntil; the quotes with the expired flag or past
        // validUntil, the expired and open quotes and the child customer's quote do not count
        self::assertSame(
            [
                'awaiting_quote_count' => 3,
                'nearest_quote_expiry_date' => $this->toUserDate(
                    new \DateTimeImmutable('2099-06-30 10:00:00', new \DateTimeZone('UTC'))
                ),
            ],
            $this->getFacts(LoadCustomerRfqData::CUSTOMER)
        );
    }

    /**
     * @dataProvider scenarioDataProvider
     */
    public function testEachRuleOnItsOwn(string $scenario, int $expectedCount, ?string $expectedNearest): void
    {
        $facts = $this->getFacts(LoadCustomerRfqData::AWAITING_PREFIX . $scenario);

        self::assertSame($expectedCount, $facts['awaiting_quote_count']);
        if ('today' === $expectedNearest) {
            // the date stored by the fixture, not a second reading of the clock, so midnight cannot split them
            $expectedNearest = $this->toUserDate(
                $this->getReference(LoadCustomerRfqData::LATER_TODAY_QUOTE)->getValidUntil()
            );
        } elseif (null !== $expectedNearest) {
            $expectedNearest = $this->toUserDate(
                new \DateTimeImmutable($expectedNearest . ' 10:00:00', new \DateTimeZone('UTC'))
            );
        }
        self::assertSame($expectedNearest, $facts['nearest_quote_expiry_date'] ?? null);
    }

    public static function scenarioDataProvider(): array
    {
        $data = [];
        foreach (LoadCustomerRfqData::AWAITING_SCENARIOS as $scenario => [, $count, $nearest]) {
            $data[$scenario] = [$scenario, $count, $nearest];
        }

        return $data;
    }

    public function testCustomerWithoutQuotesCountsZero(): void
    {
        self::assertSame(
            ['awaiting_quote_count' => 0],
            $this->getFacts(LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS)
        );
    }

    /**
     * One query for the facts, and one more for the records to link to when there is something to count.
     */
    public function testQueryCountDoesNotDependOnTheNumberOfQuotes(): void
    {
        $source = $this->getSource();
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $this->getReference(LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS));

        $expected = [LoadCustomerRfqData::CUSTOMER => 2, LoadCustomerRfqData::CUSTOMER_WITHOUT_RFQS => 1];
        foreach ($expected as $reference => $queries) {
            $customer = $this->getReference($reference);
            self::assertSame(
                $queries,
                $this->countQueries(Quote::class, static fn () => $source->addFacts(new FactSet(), $customer)),
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

    private function getSource(): AwaitingQuoteSource
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.awaiting_quote_source');
    }
}
