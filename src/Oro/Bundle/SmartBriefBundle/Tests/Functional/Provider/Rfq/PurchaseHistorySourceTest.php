<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Rfq;

use Doctrine\DBAL\ParameterType;
use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqPurchaseData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Covers the RFQ purchase facts, through the RFQ provider.
 *
 * @dbIsolationPerTest
 */
final class PurchaseHistorySourceTest extends WebTestCase
{
    use QueryCountTrait;

    private const array PURCHASE_KEYS = [
        'requested_product_count',
        'previously_purchased_product_count',
        'last_purchase_date',
    ];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqPurchaseData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testPurchasedProductsAndTheLatestPurchase(): void
    {
        // product-1 (closed twice, counted once; latest 08-14) and product-2 (open, 07-01): the range of their last
        // purchases; product-3 only in a cancelled order, a closed sub-order of a cancelled order, a child customer's
        // and another customer's order; the deleted product is not requested
        self::assertSame(
            [
                'requested_product_count' => 3,
                'previously_purchased_product_count' => 2,
                'last_purchase_date' => LoadRfqPurchaseData::lastYear('07-01')
                    . '/' . LoadRfqPurchaseData::lastYear('08-14'),
            ],
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_PURCHASE)
        );
        self::assertCount(3, $this->getLinkedOrderNumbers(), 'The three purchases with a requested product.');
    }

    /**
     * An order dated in the future, such as an advance order of a recurring order, is not a purchase yet.
     */
    public function testOrderDatedInTheFutureIsNotAPurchase(): void
    {
        // the open order of product-2
        $this->moveProduct2Purchase(new \DateTime('+3 days', new \DateTimeZone('UTC')));

        self::assertSame(
            [
                'requested_product_count' => 3,
                'previously_purchased_product_count' => 1,
                'last_purchase_date' => LoadRfqPurchaseData::lastYear('08-14'),
            ],
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_PURCHASE)
        );
    }

    /**
     * Only the purchases of the last 2 years count: an older order neither counts its products nor is linked.
     */
    public function testOrderOlderThanThePurchaseHistoryPeriodIsNotAPurchase(): void
    {
        $this->moveProduct2Purchase(new \DateTime('-2 years -1 day', new \DateTimeZone('UTC')));

        self::assertSame(
            [
                'requested_product_count' => 3,
                'previously_purchased_product_count' => 1,
                'last_purchase_date' => LoadRfqPurchaseData::lastYear('08-14'),
            ],
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_PURCHASE)
        );
        self::assertCount(2, $this->getLinkedOrderNumbers(), 'Only the two product-1 purchases.');
    }

    public function testOrderJustWithinThePurchaseHistoryPeriodIsAPurchase(): void
    {
        $createdAt = new \DateTime('-2 years +1 day', new \DateTimeZone('UTC'));
        $this->moveProduct2Purchase($createdAt);

        self::assertSame(
            [
                'requested_product_count' => 3,
                'previously_purchased_product_count' => 2,
                'last_purchase_date' => $createdAt->format('Y-m-d') . '/' . LoadRfqPurchaseData::lastYear('08-14'),
            ],
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_PURCHASE)
        );
        self::assertCount(3, $this->getLinkedOrderNumbers());
    }

    public function testNoPurchaseIsAZeroCountWithoutDate(): void
    {
        self::assertSame(
            ['requested_product_count' => 1, 'previously_purchased_product_count' => 0],
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_NO_PURCHASE)
        );
    }

    public function testNoRequestedProductsNeedNoQuery(): void
    {
        $source = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq.purchase_history_source');
        $customer = $this->getReference(LoadRfqPurchaseData::RFQ_PURCHASE)->getCustomer();

        // warm up the class-level ACL checks so only the source's own queries are counted
        $source->addFacts(new FactSet(), $customer, []);

        $facts = new FactSet();
        $latest = null;
        self::assertSame(0, $this->countQueries(Order::class, function () use ($source, $customer, $facts, &$latest) {
            $latest = $source->addFacts($facts, $customer, []);
        }));
        self::assertSame([], $latest);
        self::assertSame(
            ['previously_purchased_product_count' => 0],
            array_column(array_map(static fn ($fact) => (array)$fact, iterator_to_array($facts)), 'value', 'key')
        );
    }

    public function testLastPurchaseDateIsTheUsersCalendarDate(): void
    {
        $configManager = self::getContainer()->get('oro_config.global');
        $configManager->set('oro_locale.timezone', 'America/Los_Angeles');
        $configManager->flush();
        // the locale settings keep the timezone they read first
        (new \ReflectionProperty(LocaleSettings::class, 'timeZone'))
            ->setValue(self::getContainer()->get('oro_locale.settings'), null);

        // 08-14 03:00 UTC is still 08-13 in Los Angeles
        self::assertSame(
            LoadRfqPurchaseData::lastYear('07-01') . '/' . LoadRfqPurchaseData::lastYear('08-13'),
            $this->getPurchaseFacts(LoadRfqPurchaseData::RFQ_PURCHASE)['last_purchase_date'] ?? null
        );
    }

    public function testQueryCountDoesNotDependOnTheNumberOfLines(): void
    {
        $provider = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq');
        $oneLine = $this->getReference(LoadRfqPurchaseData::RFQ_ONE_LINE);
        $fiftyLines = $this->getReference(LoadRfqPurchaseData::RFQ_FIFTY_LINES);
        // warm up metadata and ACL caches so only the provider's own queries are counted
        $provider->getFacts($oneLine);
        $provider->getFacts($fiftyLines);

        $oneLineCount = $this->countQueries(Request::class, static fn () => $provider->getFacts($oneLine));

        self::assertGreaterThan(0, $oneLineCount);
        self::assertSame(
            $oneLineCount,
            $this->countQueries(Request::class, static fn () => $provider->getFacts($fiftyLines))
        );
    }

    /**
     * Moves the open order of product-2 (created on 07-01 of the previous year) to another date.
     */
    private function moveProduct2Purchase(\DateTime $createdAt): void
    {
        $customer = $this->getReference(LoadRfqPurchaseData::RFQ_PURCHASE)->getCustomer();
        $updated = self::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'UPDATE oro_order SET created_at = :createdAt WHERE customer_id = :customer AND created_at = :fixtureDate',
            [
                'createdAt' => $createdAt->format('Y-m-d H:i:s'),
                'customer' => $customer->getId(),
                'fixtureDate' => LoadRfqPurchaseData::lastYear('07-01 10:00:00'),
            ],
            ['customer' => ParameterType::INTEGER]
        );
        self::assertSame(1, $updated, 'The product-2 purchase was moved.');
    }

    /**
     * @return list<int|string>|null the numbers of the orders the purchase facts link to
     */
    private function getLinkedOrderNumbers(): ?array
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference(LoadRfqPurchaseData::RFQ_PURCHASE))
            ->getRecords('previously_purchased_product_count');
    }

    /**
     * @return array<string, mixed> the purchase facts by key
     */
    private function getPurchaseFacts(string $rfq): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference($rfq));
        $values = [];
        foreach ($facts as $fact) {
            if (\in_array($fact->key, self::PURCHASE_KEYS, true)) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
