<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager as EntityConfigManager;
use Oro\Bundle\EntityConfigBundle\Metadata\EntityMetadata;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\KeyFactsProvider;
use Oro\Component\Testing\ReflectionUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the key facts rows (no hidden facts, lines with their lead fact, details, parts and icons, facts
 * on their own) and the links of the lines, as defined by the provider and as a project's extension adds to them.
 */
final class KeyFactsProviderTest extends TestCase
{
    private FactFormatter&MockObject $formatter;
    private TranslatorInterface&MockObject $translator;
    private AuthorizationCheckerInterface&MockObject $authorizationChecker;
    private EntityConfigManager&MockObject $entityConfigManager;
    private bool $granted = true;

    #[\Override]
    protected function setUp(): void
    {
        $this->formatter = $this->createMock(FactFormatter::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->expects(self::any())
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []) => isset($parameters['%value%'])
                ? sprintf(' [%s %s]', substr($id, strrpos($id, '.') + 1), $parameters['%value%'])
                : $id);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->expects(self::any())
            ->method('isGranted')
            ->willReturnCallback(fn () => $this->granted);
        $this->entityConfigManager = $this->createMock(EntityConfigManager::class);
    }

    public function testRowsLeaveOutTheHiddenFacts(): void
    {
        $this->expectFormattedFacts([
            self::fact('company_name', 'Company', 'Acme'),
            self::fact('active_quote_count', 'Active quotes', '2'),
            self::fact('list_gap_percent_range', 'Against list', '-15.0% to -8.0%'),
            self::fact('last_paid_gap_percent_range', 'Against last paid', '-38.8% to -26.4%'),
            self::fact('custom_count', 'Custom', '4'),
        ]);

        self::assertEquals(
            [
                self::row('active_quotes', 'oro_smart_brief.block.line.active_quotes.label', '2', 'fa-file-o'),
                self::fact('custom_count', 'Custom', '4') + [
                    'icon' => 'fa-hashtag',
                    'parts' => [['before' => '', 'value' => '4', 'after' => '']],
                ],
            ],
            $this->createProvider()->getRows($this->createView()),
            'A fact in no line is shown on its own, under its own label and key.'
        );
    }

    public function testALineTakesItsDetailsWithTheirParts(): void
    {
        $this->expectFormattedFacts([
            self::fact('last_order_date', 'Last order date', '1 Oct 2026 (6 days ago)'),
            self::fact('open_rfq_count', 'Open RFQs', '0'),
            self::fact('last_order_total', 'Last order total', '$1,200.00'),
            self::fact('last_contact_channel', 'Last contact channel', 'Email'),
        ]);

        self::assertEquals(
            [
                [
                    'key' => 'last_order',
                    'label' => 'oro_smart_brief.block.line.last_order.label',
                    'value' => '1 Oct 2026 (6 days ago) [last_order_total $1,200.00]',
                    'icon' => 'fa-shopping-cart',
                    'parts' => [
                        ['before' => '', 'value' => '1 Oct 2026 (6 days ago)', 'after' => ''],
                        ['before' => ' [last_order_total ', 'value' => '$1,200.00', 'after' => ']'],
                    ],
                ],
                self::row('open_rfq', 'oro_smart_brief.block.line.open_rfq.label', '0', 'fa-file-text-o'),
                self::fact('last_contact_channel', 'Last contact channel', 'Email') + [
                    'icon' => 'fa-info-circle',
                    'parts' => [['before' => '', 'value' => 'Email', 'after' => '']],
                ],
            ],
            $this->createProvider()->getRows($this->createView()),
            'A line is where its lead fact is; without its lead fact, a detail is shown on its own.'
        );
    }

    /**
     * @dataProvider purchaseFactsDataProvider
     */
    public function testTheFirstDefinedLineTakesASharedFact(array $facts, array $expectedKeys): void
    {
        $this->expectFormattedFacts($facts);

        self::assertSame(
            $expectedKeys,
            array_column($this->createProvider()->getRows($this->createView()), 'key')
        );
    }

    public static function purchaseFactsDataProvider(): array
    {
        $requested = self::fact('requested_product_count', 'Requested products', '21');

        return [
            'with the purchases: the requested products are a detail' => [
                [self::fact('previously_purchased_product_count', 'Purchased before', '5'), $requested],
                ['previously_purchased'],
            ],
            'without them: the requested products have their own line' => [[$requested], ['requested_products']],
        ];
    }

    public function testGridLinkFiltersToTheRecordsOnTheCustomerPage(): void
    {
        $customer = $this->createCustomer(5);

        self::assertSame(
            [
                'url' => 'oro_customer_customer_view?' . http_build_query([
                    'id' => 5,
                    'grid' => ['customer-view-rfq-grid' => 'f%5Bid%5D%5Bvalue%5D=4%2C10&f%5Bid%5D%5Btype%5D=9'],
                ]),
                'label' => 'oro_smart_brief.block.link.open_rfq',
                'grid' => 'customer-view-rfq-grid',
                'filters' => ['id' => ['value' => '4,10', 'type' => '9']],
                'section' => null,
            ],
            $this->createProvider()
                ->getLink($this->createView('open_rfq_count', 2, [4, 10]), $customer, 'open_rfq')
        );
    }

    public function testMoreRecordsThanTheLimitLinkToTheGridWithoutAFilter(): void
    {
        $customer = $this->createCustomer(5);

        $link = $this->createProvider()
            ->getLink($this->createView('awaiting_quote_count', 25), $customer, 'awaiting_quote');

        self::assertSame('oro_customer_customer_view?id=5', $link['url']);
        self::assertSame('customer-view-quote-grid', $link['grid']);
        self::assertSame([], $link['filters'], 'An empty filter shows all the quotes.');
    }

    public function testRfqLinesLinkToTheGridOfItsCustomerPage(): void
    {
        $rfq = new Request();
        ReflectionUtil::setId($rfq, 10);
        $rfq->setCustomer($this->createCustomer(5));

        $link = $this->createProvider()
            ->getLink($this->createView('active_quote_count', 2, ['8', '16']), $rfq, 'active_quotes');

        self::assertSame(
            'oro_customer_customer_view?' . http_build_query([
                'id' => 5,
                'grid' => ['customer-view-quote-grid' => 'f%5Bqid%5D%5Bvalue%5D=8%2C16&f%5Bqid%5D%5Btype%5D=6'],
            ]),
            $link['url']
        );
    }

    public function testSectionLinkScrollsWithinTheRfqPage(): void
    {
        $rfq = new Request();
        ReflectionUtil::setId($rfq, 10);

        self::assertSame(
            [
                'url' => 'oro_rfp_request_view?id=10',
                'label' => 'oro_smart_brief.block.link.requested_products',
                'grid' => null,
                'filters' => [],
                'section' => '.rfp-line-items-info',
            ],
            $this->createProvider()
                ->getLink($this->createView('requested_product_count', 3), $rfq, 'requested_products')
        );
    }

    /**
     * @dataProvider noLinkDataProvider
     */
    public function testNoLink(string $lead, string $line, mixed $value, bool $granted, ?object $entity = null): void
    {
        $this->granted = $granted;

        self::assertNull($this->createProvider()->getLink(
            $this->createView($lead, $value, [1]),
            $entity ?? $this->createCustomer(5),
            $line
        ));
    }

    public static function noLinkDataProvider(): array
    {
        return [
            'nothing behind a zero count' => ['open_rfq_count', 'open_rfq', 0, true],
            'no VIEW on the page record' => ['open_rfq_count', 'open_rfq', 2, false],
            'a fact on its own' => ['custom_count', 'custom_count', 2, true],
            'a guest RFQ has no customer page' => ['active_quote_count', 'active_quotes', 2, true, new Request()],
        ];
    }

    public function testLastContactLinksToTheActivityRecord(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('last_contact_date', FactValueType::DATE, '2026-10-01'));
        $facts->setRecords('last_contact_date', [Email::class, 501]);
        $metadata = new EntityMetadata(Email::class);
        $metadata->routeView = 'oro_email_thread_view';
        $this->entityConfigManager->expects(self::once())
            ->method('getEntityMetadata')
            ->with(Email::class)
            ->willReturn($metadata);

        $link = $this->createProvider()->getLink(
            new SummaryView(null, $facts, false, new \DateTimeImmutable()),
            $this->createCustomer(5),
            'last_contact'
        );

        self::assertSame('oro_email_thread_view?id=501', $link['url']);
        self::assertSame('oro_smart_brief.block.link.last_contact', $link['label']);
        self::assertNull($link['grid']);
    }

    public function testLinkedGridsComeFromTheLines(): void
    {
        self::assertEqualsCanonicalizing(
            ['customer-orders-grid', 'customer-view-rfq-grid', 'customer-view-quote-grid', 'request-view-quotes-grid'],
            $this->createProvider()->getLinkedGrids()
        );
    }

    public function testAProjectAddsALineWithItsIconAndLink(): void
    {
        $this->expectFormattedFacts([
            self::fact('acme_ticket_count', 'Open tickets', '3'),
            self::fact('acme_oldest_ticket_date', 'Oldest ticket', '1 Oct 2026'),
        ]);
        $provider = $this->createProjectProvider([
            'acme_tickets' => [
                'lead' => 'acme_ticket_count',
                'details' => ['acme_oldest_ticket_date'],
                'icon' => 'fa-ticket',
                'link' => ['page' => 'customer', 'grid' => 'acme-tickets-grid', 'filter' => 'id', 'filter_type' => 9],
            ],
        ]);

        $rows = $provider->getRows($this->createView());
        $link = $provider
            ->getLink($this->createView('acme_ticket_count', 3, [7, 8, 9]), $this->createCustomer(5), 'acme_tickets');

        self::assertSame(['acme_tickets'], array_column($rows, 'key'));
        self::assertSame('fa-ticket', $rows[0]['icon']);
        self::assertSame(['id' => ['value' => '7,8,9', 'type' => '9']], $link['filters']);
        self::assertContains('acme-tickets-grid', $provider->getLinkedGrids());
    }

    /**
     * @dataProvider invalidLineDataProvider
     */
    public function testAnInvalidLineFailsLoudly(array $line, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $this->createProjectProvider(['acme' => $line])->getLinkedGrids();
    }

    public static function invalidLineDataProvider(): array
    {
        return [
            'no lead fact' => [['details' => ['x']], 'The key facts line "acme" has no lead fact.'],
            'a page link without a page' => [
                ['lead' => 'x', 'link' => ['grid' => 'g', 'filter' => 'id', 'filter_type' => 9]],
                'The link of the key facts line "acme" has no page.',
            ],
        ];
    }

    private function createProjectProvider(array $extraLines): KeyFactsProvider
    {
        return new class (
            $extraLines,
            $this->formatter,
            $this->translator,
            $this->createUrlGenerator(),
            $this->authorizationChecker,
            $this->entityConfigManager
        ) extends KeyFactsProvider {
            public function __construct(
                private readonly array $extraLines,
                FactFormatter $factFormatter,
                TranslatorInterface $translator,
                UrlGeneratorInterface $urlGenerator,
                AuthorizationCheckerInterface $authorizationChecker,
                EntityConfigManager $entityConfigManager
            ) {
                parent::__construct(
                    $factFormatter,
                    $translator,
                    $urlGenerator,
                    $authorizationChecker,
                    $entityConfigManager
                );
            }

            #[\Override]
            protected function getLines(): array
            {
                return parent::getLines() + $this->extraLines;
            }
        };
    }

    private function createProvider(): KeyFactsProvider
    {
        return new KeyFactsProvider(
            $this->formatter,
            $this->translator,
            $this->createUrlGenerator(),
            $this->authorizationChecker,
            $this->entityConfigManager
        );
    }

    private function createUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::any())
            ->method('generate')
            ->willReturnCallback(
                static fn (string $route, array $parameters) => $route . '?' . http_build_query($parameters)
            );

        return $urlGenerator;
    }

    private function expectFormattedFacts(array $rows): void
    {
        $this->formatter->expects(self::once())->method('format')->with(self::isInstanceOf(FactSet::class), true)
            ->willReturn($rows);
    }

    private function createView(?string $key = null, mixed $value = null, ?array $records = null): SummaryView
    {
        $facts = new FactSet();
        if (null !== $key) {
            $facts->add(new Fact($key, \is_int($value) ? FactValueType::INTEGER : FactValueType::TEXT, $value));
            if (null !== $records) {
                $facts->setRecords($key, $records);
            }
        }

        return new SummaryView(null, $facts, false, new \DateTimeImmutable());
    }

    private function createCustomer(int $id): Customer
    {
        $customer = new Customer();
        ReflectionUtil::setId($customer, $id);

        return $customer;
    }

    private static function fact(string $key, string $label, string $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value];
    }

    /**
     * A plain fact as a row: one part, its value.
     */
    private static function row(string $key, string $label, string $value, string $icon): array
    {
        return self::fact($key, $label, $value) + [
            'icon' => $icon,
            'parts' => [['before' => '', 'value' => $value, 'after' => '']],
        ];
    }
}
