<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Rfq;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\PricingBundle\Entity\ProductPrice;
use Oro\Bundle\ProductBundle\Tests\Functional\DataFixtures\LoadProductData;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqPriceData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the RFQ price comparison ranges against a combined price list and orders. Every test runs in its own
 * transaction.
 *
 * @dbIsolationPerTest
 */
final class RfqItemFactsTest extends WebTestCase
{
    use QueryCountTrait;
    use RolePermissionExtension;

    private const string PERFORMANCE_ENV = 'ORO_SMART_BRIEF_PERFORMANCE';

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqPriceData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testPriceGapRangesAndExclusions(): void
    {
        // item A: +8.3 % against its list price 8.40 (by quantity tier) and +2.2 % against the last paid 8.90 (the
        // latest of two purchases by date); item D: -2.3 % against its list price, rounded half away from zero, never
        // bought; items B, E and product-8 (bought only on a bundled line), the kit and the incomplete items: no
        // comparison
        self::assertSame(
            ['list_gap_percent_range' => '-2.3/8.3', 'last_paid_gap_percent_range' => '2.2'],
            $this->getItemValues(LoadRfqPriceData::RFQ_PRICED)
        );
    }

    public function testWithoutAWebsiteNoListPriceRange(): void
    {
        self::assertSame(
            ['last_paid_gap_percent_range' => '2.2'],
            $this->getItemValues(LoadRfqPriceData::RFQ_PRICED_NO_WEBSITE)
        );
    }

    public function testWithoutProductPriceViewNoListPriceRange(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, ProductPrice::class, AccessLevel::NONE_LEVEL);

        self::assertSame(
            ['last_paid_gap_percent_range' => '2.2'],
            $this->getItemValues(LoadRfqPriceData::RFQ_PRICED)
        );
    }

    public function testWithoutOrderViewNoLastPaidRange(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::NONE_LEVEL);

        self::assertSame(
            ['list_gap_percent_range' => '-2.3/8.3'],
            $this->getItemValues(LoadRfqPriceData::RFQ_PRICED)
        );
    }

    public function testAHiddenNewerPurchaseDoesNotHideTheVisibleOne(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::BASIC_LEVEL);

        // the 08-01 purchase belongs to another user: the user's own 07-01 purchase at 9.50 is the last paid
        self::assertSame('-4.2', $this->getItemValues(LoadRfqPriceData::RFQ_PRICED)['last_paid_gap_percent_range']);
    }

    public function testAPurchaseOlderThanThePurchaseHistoryPeriodIsNoLastPaidPrice(): void
    {
        $customer = $this->getReference(LoadRfqPriceData::RFQ_PRICED)->getCustomer();
        // the 08-01 purchase at 8.90 becomes older than 2 years: the 07-01 purchase at 9.50 is the last paid
        $updated = $this->getConnection()->executeStatement(
            'UPDATE oro_order SET created_at = :createdAt WHERE customer_id = :customer AND created_at = :fixtureDate',
            [
                'createdAt' => (new \DateTime('-2 years -1 day', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'customer' => $customer->getId(),
                'fixtureDate' => LoadRfqPriceData::lastYear('08-01 10:00:00'),
            ],
            ['customer' => ParameterType::INTEGER]
        );

        self::assertSame(1, $updated);
        self::assertSame('-4.2', $this->getItemValues(LoadRfqPriceData::RFQ_PRICED)['last_paid_gap_percent_range']);
    }

    public function testQueryCountDoesNotDependOnTheNumberOfItems(): void
    {
        $provider = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq');
        $oneItem = $this->getReference(LoadRfqPriceData::RFQ_ONE_ITEM);
        $fiftyItems = $this->getReference(LoadRfqPriceData::RFQ_FIFTY_ITEMS);
        // warm up metadata, ACL and price caches so only the provider's own queries are counted
        $provider->getFacts($oneItem);
        $provider->getFacts($fiftyItems);

        $oneItemCount = $this->countQueries(Request::class, static fn () => $provider->getFacts($oneItem));

        self::assertGreaterThan(0, $oneItemCount);
        self::assertSame(
            $oneItemCount,
            $this->countQueries(Request::class, static fn () => $provider->getFacts($fiftyItems))
        );
    }

    /**
     * A customer with 100 000 more orders (older, spread over nine products, three of them the
     * RFQ's), statistics refreshed; p95 of the facts of the 50-item RFQ (list prices, last paid, purchases, quotes)
     * within 150 ms. Timing is environment-dependent, so it runs only with ORO_SMART_BRIEF_PERFORMANCE=1.
     *
     * @group performance
     */
    public function testPerformanceFixture(): void
    {
        if (!getenv(self::PERFORMANCE_ENV)) {
            self::markTestSkipped(sprintf('Set %s=1 to run the performance fixture.', self::PERFORMANCE_ENV));
        }
        $this->clonePurchases(100000);
        $connection = $this->getConnection();
        $connection->executeStatement('ANALYZE oro_order');
        $connection->executeStatement('ANALYZE oro_order_line_item');
        $connection->executeStatement('ANALYZE oro_order_line_items');

        $provider = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq');
        $rfq = $this->getReference(LoadRfqPriceData::RFQ_FIFTY_ITEMS);
        $provider->getFacts($rfq);
        $durations = [];
        for ($i = 0; $i < 20; $i++) {
            $start = hrtime(true);
            $provider->getFacts($rfq);
            $durations[] = (hrtime(true) - $start) / 1e6;
        }
        sort($durations);

        self::assertLessThanOrEqual(150.0, $durations[(int)ceil(0.95 * \count($durations)) - 1]);
    }

    /**
     * Clones the customer's product-1 purchase $copies times, a minute apart and older, each with its own copy of
     * the line item for one of nine products in turn.
     */
    private function clonePurchases(int $copies): void
    {
        $connection = $this->getConnection();
        $template = $connection->fetchAssociative(
            'SELECT o.id AS order_id, li.id AS line_id FROM oro_order o'
            . ' JOIN oro_order_line_items link ON link.order_id = o.id'
            . ' JOIN oro_order_line_item li ON li.id = link.line_item_id'
            . ' WHERE li.product_sku = :sku ORDER BY o.created_at LIMIT 1',
            ['sku' => 'product-1']
        );
        $orderColumns = $this->getColumns(
            'oro_order',
            ['id', 'identifier', 'created_at', 'shipping_address_id', 'billing_address_id']
        );
        $lineColumns = $this->getColumns('oro_order_line_item', ['id', 'product_id', 'product_sku']);
        $productIds = implode(', ', array_map(
            fn (string $sku) => (int)$this->getReference($sku)->getId(),
            [
                LoadProductData::PRODUCT_1,
                LoadProductData::PRODUCT_2,
                LoadProductData::PRODUCT_3,
                LoadProductData::PRODUCT_4,
                LoadProductData::PRODUCT_5,
                LoadProductData::PRODUCT_6,
                LoadProductData::PRODUCT_7,
                LoadProductData::PRODUCT_8,
                LoadProductData::PRODUCT_9,
            ]
        ));
        $connection->executeStatement(
            sprintf(
                'WITH new_orders AS ('
                . ' INSERT INTO oro_order (%1$s, identifier, created_at)'
                . ' SELECT %1$s, \'ai-perf-\' || g, o.created_at - make_interval(mins => g)'
                . ' FROM oro_order o, generate_series(1, :copies) g WHERE o.id = :order RETURNING id'
                . '), new_lines AS ('
                . ' INSERT INTO oro_order_line_item (%2$s, product_id, product_sku)'
                . ' SELECT %2$s, (ARRAY[%3$s])[1 + g %% 9], \'perf-\' || (g %% 9)'
                . ' FROM oro_order_line_item li, generate_series(1, :copies) g WHERE li.id = :line RETURNING id'
                . ') INSERT INTO oro_order_line_items (order_id, line_item_id)'
                . ' SELECT o.id, l.id FROM (SELECT id, row_number() OVER (ORDER BY id) AS rn FROM new_orders) o'
                . ' JOIN (SELECT id, row_number() OVER (ORDER BY id) AS rn FROM new_lines) l USING (rn)',
                $orderColumns,
                $lineColumns,
                $productIds
            ),
            ['copies' => $copies, 'order' => (int)$template['order_id'], 'line' => (int)$template['line_id']],
            ['copies' => ParameterType::INTEGER, 'order' => ParameterType::INTEGER, 'line' => ParameterType::INTEGER]
        );
    }

    private function getColumns(string $table, array $excluded): string
    {
        $connection = $this->getConnection();
        $columns = array_diff(
            array_map(
                static fn ($column) => $column->getName(),
                $connection->createSchemaManager()->listTableColumns($table)
            ),
            $excluded
        );

        return implode(', ', array_map([$connection, 'quoteIdentifier'], $columns));
    }

    private function getConnection(): Connection
    {
        return self::getContainer()->get('doctrine')->getConnection();
    }

    /**
     * @return array<string, mixed> the price comparison ranges by key
     */
    private function getItemValues(string $rfq): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference($rfq));
        $values = [];
        foreach ($facts as $fact) {
            if (str_ends_with($fact->key, '_gap_percent_range')) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
