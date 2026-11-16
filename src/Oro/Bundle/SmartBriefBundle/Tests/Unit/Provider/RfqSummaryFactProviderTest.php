<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\RFPBundle\Entity\RequestProduct;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\PurchaseHistorySource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqItemSource;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqQuoteSource;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use Oro\Component\Testing\ReflectionUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the RFQ provider (keys, type, the company name and the requested products). The quote facts and the
 * purchase facts are added by {@see RfqQuoteSource} and
 * {@see PurchaseHistorySource} (their own tests), which run only for a customer that is not a guest's; the latest
 * purchases the purchase source returns go to the item facts.
 */
final class RfqSummaryFactProviderTest extends TestCase
{
    private RfqQuoteSource&MockObject $quoteSource;
    private PurchaseHistorySource&MockObject $purchaseHistorySource;
    private RfqItemSource&MockObject $itemSource;
    private RfqSummaryFactProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->quoteSource = $this->createMock(RfqQuoteSource::class);
        $this->purchaseHistorySource = $this->createMock(PurchaseHistorySource::class);
        $this->itemSource = $this->createMock(RfqItemSource::class);
        $this->provider = new RfqSummaryFactProvider(
            $this->quoteSource,
            $this->purchaseHistorySource,
            $this->itemSource
        );
    }

    /**
     * @dataProvider classDataProvider
     */
    public function testSupportsClassFromTheNameAlone(string $class, bool $expected): void
    {
        self::assertSame($expected, $this->provider->supportsClass($class));
    }

    public static function classDataProvider(): array
    {
        return [
            'RFQ' => [Request::class, true],
            'customer' => [Customer::class, false],
            'order' => [Order::class, false],
            'RFQ subclass name' => [Request::class . 'Proxy', false],
            'unknown class string' => ['Acme\Nope', false],
        ];
    }

    public function testIdentity(): void
    {
        self::assertSame('rfq', $this->provider->getEntityType());
        self::assertSame('oro_smart_brief.rfq_enabled', $this->provider->getEnabledConfigKey());
    }

    public function testCompanyNameAndRequestedProductCount(): void
    {
        // two lines of product 11, one of product 12 and one whose product was deleted: two requested products
        $request = $this->addLines(
            (new Request())->setCustomer((new Customer())->setName('Acme Industrial')),
            [11, 11, 12, null]
        );

        self::assertEquals(
            [
                new Fact('company_name', FactValueType::TEXT, 'Acme Industrial', false),
                new Fact('requested_product_count', FactValueType::INTEGER, 2),
            ],
            array_values(iterator_to_array($this->provider->getFacts($request)))
        );
    }

    /**
     * @dataProvider missingCompanyNameDataProvider
     */
    public function testGuestOrNamelessCustomerHasNoCompanyName(Request $request): void
    {

        self::assertEquals(
            [new Fact('requested_product_count', FactValueType::INTEGER, 0, false)],
            array_values(iterator_to_array($this->provider->getFacts($request)))
        );
    }

    public static function missingCompanyNameDataProvider(): array
    {
        // the storefront names a guest's customer after the guest
        $guestCustomer = (new Customer())->setName('Amanda Cole');

        return [
            'no customer' => [new Request()],
            'guest customer user' => [
                (new Request())
                    ->setCustomer($guestCustomer)
                    ->setCustomerUser((new CustomerUser())->setIsGuest(true)->setCustomer($guestCustomer)),
            ],
            'blank name' => [(new Request())->setCustomer((new Customer())->setName("  \t "))],
        ];
    }

    public function testRegisteredCustomerUserKeepsTheCompanyName(): void
    {
        $customer = (new Customer())->setName('Acme Industrial');
        $request = (new Request())
            ->setCustomer($customer)
            ->setCustomerUser((new CustomerUser())->setIsGuest(false)->setCustomer($customer));

        $keys = array_map(
            static fn (Fact $fact) => $fact->key,
            array_values(iterator_to_array($this->provider->getFacts($request)))
        );

        self::assertSame(['company_name', 'requested_product_count'], $keys);
    }

    public function testQuoteSourceAddsItsFactsForTheRfqAndItsCustomer(): void
    {
        $customer = (new Customer())->setName('Acme Industrial');
        $request = (new Request())->setCustomer($customer);
        $this->quoteSource->expects(self::once())
            ->method('addFacts')
            ->with(self::isInstanceOf(FactSet::class), self::identicalTo($request), self::identicalTo($customer))
            ->willReturnCallback(static function (FactSet $facts): void {
                $facts->add(new Fact('active_quote_count', FactValueType::INTEGER, 2));
            });

        $keys = array_map(
            static fn (Fact $fact) => $fact->key,
            array_values(iterator_to_array($this->provider->getFacts($request)))
        );

        self::assertSame(['company_name', 'active_quote_count', 'requested_product_count'], $keys);
    }

    public function testWithoutACustomerNoQuoteQueryRuns(): void
    {
        $guestCustomer = (new Customer())->setName('Amanda Cole');
        // no quote or order source runs; the item facts still do (list prices are not customer-scoped)
        $this->quoteSource->expects(self::never())->method('addFacts');
        $this->purchaseHistorySource->expects(self::never())->method('addFacts');

        $this->provider->getFacts(new Request());
        $this->provider->getFacts(
            (new Request())
                ->setCustomer($guestCustomer)
                ->setCustomerUser((new CustomerUser())->setIsGuest(true)->setCustomer($guestCustomer))
        );
    }

    /**
     * @dataProvider purchaseReadingDataProvider
     */
    public function testPurchaseSourceAddsItsFactsAndItsPurchasesGoToTheItemFacts(
        bool $withCustomer,
        ?array $latestPurchases
    ): void {
        $customer = $withCustomer ? (new Customer())->setName('Acme Industrial') : null;
        $request = $this->addLines((new Request())->setCustomer($customer), [7]);
        $this->purchaseHistorySource->expects($withCustomer ? self::once() : self::never())
            ->method('addFacts')
            ->with(self::isInstanceOf(FactSet::class), self::identicalTo($customer), [7])
            ->willReturnCallback(static function (FactSet $facts) use ($latestPurchases): ?array {
                $facts->add(new Fact('previously_purchased_product_count', FactValueType::INTEGER, 1));

                return $latestPurchases;
            });
        $this->itemSource->expects(self::once())
            ->method('addFacts')
            ->with(
                self::isInstanceOf(FactSet::class),
                self::identicalTo($request),
                self::identicalTo($customer),
                $latestPurchases
            )
            ->willReturnCallback(static function (FactSet $facts): void {
                $facts->add(new Fact('list_gap_percent_range', FactValueType::PERCENT, '-15.0/-8.0'));
            });

        $facts = array_values(iterator_to_array($this->provider->getFacts($request)));

        self::assertSame(
            $withCustomer
                ? [
                    'company_name',
                    'requested_product_count',
                    'previously_purchased_product_count',
                    'list_gap_percent_range',
                ]
                : ['requested_product_count', 'list_gap_percent_range'],
            array_map(static fn (Fact $fact) => $fact->key, $facts)
        );
    }

    public static function purchaseReadingDataProvider(): array
    {
        $latest = [[
            'productId' => 7,
            'unitCode' => 'pc',
            'currency' => 'USD',
            'priceType' => 10,
            'latestAt' => '2026-08-14 15:00:00',
        ]];

        return [
            'customer with readable purchases' => [true, $latest],
            'customer whose purchases may not be read' => [true, null],
            'no customer' => [false, null],
        ];
    }

    /**
     * @param array<int|null> $productIds the product of each line; null for a line whose product was deleted
     */
    private function addLines(Request $request, array $productIds): Request
    {
        foreach ($productIds as $productId) {
            $product = null;
            if (null !== $productId) {
                $product = new Product();
                ReflectionUtil::setId($product, $productId);
            }
            $request->addRequestProduct((new RequestProduct())->setProduct($product));
        }

        return $request;
    }
}
