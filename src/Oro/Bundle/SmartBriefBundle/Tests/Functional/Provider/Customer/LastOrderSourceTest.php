<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastOrderSource;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerOrderData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\FactValuesTrait;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Covers the last order facts against fixture orders: top-level, non-cancelled, newest, id tie-break; orders without
 * status count; child customers excluded; the date in the user's timezone, the total as raw Price; missing data
 * skipped; one query whatever the number of orders.
 */
final class LastOrderSourceTest extends WebTestCase
{
    use FactValuesTrait;
    use QueryCountTrait;

    private const string PERFORMANCE_ENV = 'ORO_SMART_BRIEF_PERFORMANCE';

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerOrderData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    /**
     * The child customer's order (10-02), the sub-order (10-01) and the cancelled order (09-30) are newer than the
     * expected one and must all be ignored; the expected order has no internal status at all.
     */
    public function testNewestTopLevelNonCancelledOrderOfTheCustomerItself(): void
    {
        $noStatusOrder = $this->getReference(LoadCustomerOrderData::NO_STATUS_ORDER);
        self::assertNull(
            $this->getConnection()->fetchOne(
                "SELECT serialized_data->>'internal_status' FROM oro_order WHERE id = :id",
                ['id' => $noStatusOrder->getId()],
                ['id' => ParameterType::INTEGER]
            ),
            'The fixture order really has no internal status.'
        );

        $facts = $this->getFacts(LoadCustomerOrderData::CUSTOMER);

        self::assertSame(
            $this->toUserDate(new \DateTimeImmutable('2026-09-10 10:00:00', new \DateTimeZone('UTC'))),
            $facts['last_order_date']
        );
        self::assertEquals(Price::create('500.0000', 'EUR'), $facts['last_order_total']);
    }

    /**
     * An order dated in the future, such as an advance order of a recurring order, is not the last order.
     */
    public function testOrderDatedInTheFutureIsIgnored(): void
    {
        $this->getConnection()->executeStatement(
            "UPDATE oro_order SET created_at = NOW() + INTERVAL '3 days' WHERE id = :id",
            ['id' => $this->getReference(LoadCustomerOrderData::OPEN_ORDER)->getId()],
            ['id' => ParameterType::INTEGER]
        );

        $facts = $this->getFacts(LoadCustomerOrderData::CUSTOMER);

        self::assertSame(
            $this->toUserDate(new \DateTimeImmutable('2026-09-10 10:00:00', new \DateTimeZone('UTC'))),
            $facts['last_order_date']
        );
    }

    public function testSameCreationTimeIsDecidedByTheHigherId(): void
    {
        $facts = $this->getFacts(LoadCustomerOrderData::TIE_CUSTOMER);

        self::assertSame('7.0000', $facts['last_order_total']->getValue());
    }

    /**
     * @dataProvider noOrderDataProvider
     */
    public function testCustomersWithoutAQualifyingOrderGetNothing(string $customer): void
    {
        self::assertSame([], $this->getFacts($customer));
    }

    public static function noOrderDataProvider(): array
    {
        return [
            'no orders' => [LoadCustomerOrderData::CUSTOMER_WITHOUT_ORDERS],
            'only cancelled orders' => [LoadCustomerOrderData::CUSTOMER_CANCELLED_ONLY],
        ];
    }

    public function testQueryCountDoesNotDependOnTheNumberOfOrders(): void
    {
        $source = $this->getSource();
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $this->getCustomer(LoadCustomerOrderData::CUSTOMER_WITHOUT_ORDERS));

        foreach ([LoadCustomerOrderData::CUSTOMER, LoadCustomerOrderData::PERFORMANCE_CUSTOMER] as $reference) {
            $customer = $this->getCustomer($reference);
            self::assertSame(
                1,
                $this->countQueries(Order::class, static fn () => $source->addFacts(new FactSet(), $customer)),
                $reference
            );
        }
    }

    /**
     * 100 000 orders for one customer plus 200 000 newer orders of another customer (the
     * production shape that tempts the planner into a backwards created_at scan), statistics refreshed; p95 of the
     * source within 150 ms for the big customer and for a customer with only old orders, and one query each.
     * Timing is environment-dependent, so it runs only with ORO_SMART_BRIEF_PERFORMANCE=1.
     *
     * @group performance
     */
    public function testPerformanceFixture(): void
    {
        if (!getenv(self::PERFORMANCE_ENV)) {
            self::markTestSkipped(sprintf('Set %s=1 to run the performance fixture.', self::PERFORMANCE_ENV));
        }
        $template = $this->getReference(LoadCustomerOrderData::PERFORMANCE_ORDER);
        $noiseCustomer = $this->getCustomer(LoadCustomerOrderData::CUSTOMER_WITHOUT_ORDERS);
        $this->cloneOrder($template->getId(), 99999, null, '-');
        $this->cloneOrder($template->getId(), 200000, $noiseCustomer->getId(), '+');
        $this->getConnection()->executeStatement('ANALYZE oro_order');

        $source = $this->getSource();
        foreach ([LoadCustomerOrderData::PERFORMANCE_CUSTOMER, LoadCustomerOrderData::TIE_CUSTOMER] as $reference) {
            $customer = $this->getCustomer($reference);
            $source->addFacts(new FactSet(), $customer);
            $durations = [];
            for ($i = 0; $i < 20; $i++) {
                $start = hrtime(true);
                $source->addFacts(new FactSet(), $customer);
                $durations[] = (hrtime(true) - $start) / 1e6;
            }
            sort($durations);

            self::assertLessThanOrEqual(150.0, $durations[(int)ceil(0.95 * \count($durations)) - 1], $reference);
            self::assertSame(
                1,
                $this->countQueries(Order::class, static fn () => $source->addFacts(new FactSet(), $customer)),
                $reference
            );
        }
    }

    /**
     * Clones the template order $copies times, a minute apart (older with '-', newer with '+'), optionally for
     * another customer.
     */
    private function cloneOrder(int $templateId, int $copies, ?int $customerId, string $direction): void
    {
        $connection = $this->getConnection();
        $columns = array_values(array_diff(
            array_map(
                static fn ($column) => $column->getName(),
                $connection->createSchemaManager()->listTableColumns('oro_order')
            ),
            ['id', 'identifier', 'shipping_address_id', 'billing_address_id', 'created_at', 'customer_id']
        ));
        $quoted = implode(', ', array_map([$connection, 'quoteIdentifier'], $columns));
        $connection->executeStatement(
            sprintf(
                'INSERT INTO oro_order (%1$s, identifier, created_at, customer_id) '
                . 'SELECT %1$s, :prefix || g, o.created_at %2$s make_interval(mins => g), '
                . 'COALESCE(:customer, o.customer_id) '
                . 'FROM oro_order o, generate_series(1, :copies) g WHERE o.id = :id',
                $quoted,
                '+' === $direction ? '+' : '-'
            ),
            ['prefix' => 'ai-perf-' . $direction, 'customer' => $customerId, 'copies' => $copies, 'id' => $templateId],
            [
                'prefix' => ParameterType::STRING,
                'customer' => null === $customerId ? ParameterType::NULL : ParameterType::INTEGER,
                'copies' => ParameterType::INTEGER,
                'id' => ParameterType::INTEGER,
            ]
        );
    }

    private function getFacts(string $customerReference): array
    {
        $customer = $this->getCustomer($customerReference);

        return $this->getFactValues(fn (FactSet $facts) => $this->getSource()->addFacts($facts, $customer));
    }

    private function getConnection(): Connection
    {
        return self::getContainer()->get('doctrine')->getConnection();
    }

    private function getSource(): LastOrderSource
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.last_order_source');
    }

    private function getCustomer(string $reference): Customer
    {
        return $this->getReference($reference);
    }
}
