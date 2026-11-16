<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\AwaitingQuoteSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastContactSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastOrderSource;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\OpenRfqSource;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Customer provider (keys, type, switch, company name), and that every source adds its
 * facts to the same set after the company name, in the order of the fact keys. The facts of each source are
 * covered by the source's own test.
 */
final class CustomerSummaryFactProviderTest extends TestCase
{
    private LastOrderSource&MockObject $lastOrderSource;
    private OpenRfqSource&MockObject $openRfqSource;
    private AwaitingQuoteSource&MockObject $awaitingQuoteSource;
    private LastContactSource&MockObject $lastContactSource;
    private CustomerSummaryFactProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->lastOrderSource = $this->createMock(LastOrderSource::class);
        $this->openRfqSource = $this->createMock(OpenRfqSource::class);
        $this->awaitingQuoteSource = $this->createMock(AwaitingQuoteSource::class);
        $this->lastContactSource = $this->createMock(LastContactSource::class);
        $this->provider = new CustomerSummaryFactProvider(
            $this->lastOrderSource,
            $this->openRfqSource,
            $this->awaitingQuoteSource,
            $this->lastContactSource
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
            'customer' => [Customer::class, true],
            'order' => [Order::class, false],
            'rfq' => [Request::class, false],
            'unknown class string' => ['Acme\Nope', false],
            'subclass or proxy name' => ['Proxies\__CG__\Oro\Bundle\CustomerBundle\Entity\Customer', false],
        ];
    }

    public function testIdentity(): void
    {
        self::assertSame('customer', $this->provider->getEntityType());
        self::assertSame('oro_smart_brief.customer_enabled', $this->provider->getEnabledConfigKey());
    }

    public function testCompanyNameIsAnIdentityFactWithTheStoredName(): void
    {
        $customer = (new Customer())->setName('  Acme Industrial ');

        $facts = iterator_to_array($this->provider->getFacts($customer));

        self::assertEquals(
            [new Fact('company_name', FactValueType::TEXT, '  Acme Industrial ', false)],
            array_values($facts)
        );
    }

    /**
     * @dataProvider missingNameDataProvider
     */
    public function testMissingNameIsSkippedWithoutAPlaceholder(?string $name): void
    {
        $customer = new Customer();
        if (null !== $name) {
            $customer->setName($name);
        }

        self::assertCount(0, $this->provider->getFacts($customer));
    }

    public static function missingNameDataProvider(): array
    {
        return [
            'never set' => [null],
            'empty' => [''],
            'whitespace' => ["  \t "],
        ];
    }

    public function testEverySourceAddsItsFactsToTheSameSetAfterTheCompanyName(): void
    {
        $customer = (new Customer())->setName('Acme Industrial');
        $set = null;
        $sources = [
            'last_order_date' => $this->lastOrderSource,
            'open_rfq_count' => $this->openRfqSource,
            'awaiting_quote_count' => $this->awaitingQuoteSource,
            'last_contact_date' => $this->lastContactSource,
        ];
        foreach ($sources as $key => $source) {
            $source->expects(self::once())
                ->method('addFacts')
                ->with(self::isInstanceOf(FactSet::class), self::identicalTo($customer))
                ->willReturnCallback(static function (FactSet $facts) use ($key, &$set): void {
                    self::assertTrue(null === $set || $set === $facts, 'One fact set for every source.');
                    $set = $facts;
                    $facts->add(new Fact($key, FactValueType::INTEGER, 1));
                });
        }

        $facts = $this->provider->getFacts($customer);

        self::assertSame($set, $facts);
        self::assertSame(
            ['company_name', 'last_order_date', 'open_rfq_count', 'awaiting_quote_count', 'last_contact_date'],
            array_map(static fn (Fact $fact) => $fact->key, array_values(iterator_to_array($facts)))
        );
    }
}
