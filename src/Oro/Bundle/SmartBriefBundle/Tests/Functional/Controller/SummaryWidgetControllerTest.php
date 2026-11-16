<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Controller;

use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Tests\Functional\DataFixtures\LoadCustomers;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadOpenAiChannelData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqPriceData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\CustomerFactProviderStub;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\OpenAiSummaryClientFactoryStub;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers the widget route: the record page never carries the summary, the uniform 404 refusals and the
 * feature gate. The presentation tests take the Customer facts from the switchable CustomerFactProviderStub, a
 * fixed set of display shapes (a date a fixed number of days back, an item group) that real data cannot give
 * without depending on today's date; the RFQ test renders real item facts end to end.
 * That the session is released before the AI call cannot be observed with the basic-auth test client; it is
 * covered by the unit test of the controller.
 */
final class SummaryWidgetControllerTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;
    use RolePermissionExtension;

    private const string CUSTOMER_CLASS_ALIAS = 'Oro_Bundle_CustomerBundle_Entity_Customer';
    private const string SUMMARY_TEXT = 'Stub summary for the widget.';
    /** The whole ACE is replaced by the helper, so every permission is restored, not only VIEW. */
    private const array FULL_CUSTOMER_ACCESS = [
        'VIEW' => AccessLevel::SYSTEM_LEVEL,
        'CREATE' => AccessLevel::SYSTEM_LEVEL,
        'EDIT' => AccessLevel::SYSTEM_LEVEL,
        'DELETE' => AccessLevel::SYSTEM_LEVEL,
        'ASSIGN' => AccessLevel::SYSTEM_LEVEL,
    ];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomers::class, LoadOpenAiChannelData::class, LoadRfqPriceData::class]);
        $this->setConfigValue('oro_smart_brief.enabled', true);
        $this->setConfigValue(
            'oro_smart_brief.integration',
            $this->getReference(LoadOpenAiChannelData::CHANNEL)->getId()
        );
        $this->setConfigValue('oro_smart_brief.customer_enabled', true);
        CustomerFactProviderStub::enable();
        $this->resetFeatureCache();
        OpenAiSummaryClientFactoryStub::reset();
        OpenAiSummaryClientFactoryStub::respondWith(new SummaryResponse(self::SUMMARY_TEXT, 'stub-model', 10, 5));
        // Drops the summary cached by a previous test (same user/record key).
        self::getContainer()->get('oro_smart_brief.tests.cache')->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CustomerFactProviderStub::reset();
        $this->restoreConfigValues();
        $this->resetFeatureCache();
        OpenAiSummaryClientFactoryStub::reset();
        self::getContainer()->get('oro_smart_brief.tests.cache')->clear();
        parent::tearDown();
    }

    public function testRecordPageCarriesTheContainerButNotTheSummary(): void
    {
        $crawler = $this->client->request(
            'GET',
            $this->getUrl('oro_customer_customer_view', ['id' => $this->getCustomer()->getId()])
        );

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        self::assertCount(1, $crawler->filter('.smart-brief'));
        self::assertCount(1, $crawler->filter('.smart-brief [data-page-component-module]'));
        self::assertStringNotContainsString(self::SUMMARY_TEXT, $this->client->getResponse()->getContent());
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient(), 'The page must not call the AI.');
    }

    /**
     * A collapsible section that starts expanded — the title, the paragraph's first line, how long ago it was
     * written and the collapse button; the body holds the AI paragraph with the "Key facts" column next to it, in the
     * emitted order; no Regenerate button unless enabled. The facts fingerprint lets the browser keep the user's
     * choice.
     */
    public function testWidgetRendersTheSummary(): void
    {
        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        $summary = $crawler->filter('.widget-content.smart-brief-panel');
        self::assertCount(1, $summary);
        self::assertCount(0, $crawler->filter('[data-smart-brief-empty], [data-smart-brief-fallback]'));
        self::assertSame('1', $summary->attr('data-smart-brief-expanded-default'), 'The block starts expanded.');
        self::assertStringNotContainsString('smart-brief-panel--collapsed', $summary->attr('class'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $summary->attr('data-smart-brief-fingerprint'));
        self::assertSame('true', $summary->filter('[data-role="smart-brief-collapse"]')->attr('aria-expanded'));
        self::assertSame('AI Smart Brief', trim($summary->filter('h4.smart-brief-panel__title')->text()));
        self::assertSame(self::SUMMARY_TEXT, trim($summary->filter('.smart-brief-panel__teaser')->text()));
        self::assertSame('Updated just now', trim($summary->filter('.smart-brief-panel__age')->text()));
        self::assertCount(1, $summary->filter('[data-role="smart-brief-collapse"]'));
        self::assertSame(self::SUMMARY_TEXT, trim($summary->filter('.smart-brief-panel__text')->text()));
        self::assertStringContainsString('smart-brief-panel--with-facts', $summary->attr('class'));
        self::assertSame('Key facts', trim($summary->filter('.smart-brief-panel__facts-title')->text()));
        self::assertSame(
            ['open_rfq', 'last_order'],
            $summary->filter('[data-smart-brief-line]')
                ->each(static fn ($node) => $node->attr('data-smart-brief-line')),
            'Key facts keep the emitted order.'
        );
        self::assertCount(0, $summary->filter('[data-role="smart-brief-regenerate"], form, input'));
    }

    /**
     * A key facts line links to the grid of the records behind it; with the stub's count and no records the link
     * shows the whole grid of the customer's page.
     */
    public function testKeyFactsLinkToTheGridOfTheCustomerPage(): void
    {
        $customer = $this->getCustomer();

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $customer->getId());

        $link = $crawler->filter('[data-smart-brief-line="open_rfq"] [data-role="smart-brief-link"]');
        self::assertCount(1, $link);
        self::assertSame('customer-view-rfq-grid', $link->attr('data-grid'));
        self::assertSame('[]', $link->attr('data-filters'));
        self::assertSame(
            $this->getUrl('oro_customer_customer_view', ['id' => $customer->getId()]),
            $link->attr('href')
        );
        self::assertSame('Show the RFQs', $link->attr('aria-label'));
    }

    /**
     * The grids the block links to get the SmartBrief builder, which filters them in place.
     */
    public function testLinkedGridsGetTheFilterBuilder(): void
    {
        $datagrid = self::getContainer()->get('oro_datagrid.datagrid.manager')
            ->getDatagrid('customer-view-rfq-grid', ['customer_id' => $this->getCustomer()->getId()]);

        self::assertContains(
            'orosmartbrief/js/datagrid/smart-brief-filter-builder',
            $datagrid->getConfig()->offsetGetByPath('[options][jsmodules]', [])
        );
    }

    public function testBlockStartsCollapsedWhenNotExpandedByDefault(): void
    {
        $this->setConfigValue('oro_smart_brief.expanded', false);

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        $summary = $crawler->filter('.widget-content.smart-brief-panel');
        self::assertSame('0', $summary->attr('data-smart-brief-expanded-default'));
        self::assertStringContainsString('smart-brief-panel--collapsed', $summary->attr('class'));
        self::assertSame('false', $summary->filter('[data-role="smart-brief-collapse"]')->attr('aria-expanded'));
    }

    /**
     * The Regenerate button: shown only when enabled; `refresh=1` writes the summary again (and replaces the cached
     * one) only while it is enabled.
     */
    public function testRegenerateWritesTheSummaryAgainOnlyWhileEnabled(): void
    {
        $customerId = $this->getCustomer()->getId();
        $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $customerId);
        self::assertCount(1, OpenAiSummaryClientFactoryStub::getLastClient()->getRequests());

        $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $customerId, ['refresh' => 1]);
        self::assertCount(
            1,
            OpenAiSummaryClientFactoryStub::getLastClient()->getRequests(),
            'With Regenerate disabled the cached summary is served.'
        );

        $this->setConfigValue('oro_smart_brief.regenerate_enabled', true);
        OpenAiSummaryClientFactoryStub::respondWith(new SummaryResponse('Regenerated summary.', 'stub-model', 10, 5));
        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $customerId, ['refresh' => 1]);

        self::assertCount(1, $crawler->filter('[data-role="smart-brief-regenerate"]'));
        self::assertSame('Regenerated summary.', trim($crawler->filter('.smart-brief-panel__text')->text()));
        $cached = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $customerId);
        self::assertSame(
            'Regenerated summary.',
            trim($cached->filter('.smart-brief-panel__text')->text()),
            'The regenerated summary replaces the cached one.'
        );
    }

    /**
     * Values are Oro's and dates carry the relative day text in bullets.
     */
    public function testBulletsAreFormattedByTheFormatter(): void
    {
        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        $stub = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer');
        $date = $stub->getDateDaysAgo(CustomerFactProviderStub::DATE_FACT_DAYS_AGO);
        $expectedDate = self::getContainer()->get('oro_locale.formatter.date_time')
            ->formatDate($date, null, null, 'UTC');
        $dateValue = trim(
            $crawler->filter('[data-smart-brief-line="last_order"] .smart-brief-panel__fact-value')->text()
        );
        self::assertSame(sprintf('%s (54 days ago)', $expectedDate), $dateValue);
    }

    /**
     * The real RFQ provider end to end (stub-free): the price ranges go to the AI only; the key facts show the
     * record facts but never the ranges.
     */
    public function testRfqWidgetSendsThePriceRangesToTheAiButNeverShowsThem(): void
    {
        $this->setConfigValue('oro_smart_brief.rfq_enabled', true);
        $rfq = $this->getReference(LoadRfqPriceData::RFQ_PRICED);

        $crawler = $this->requestWidget('Oro_Bundle_RFPBundle_Entity_Request', $rfq->getId());

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        self::assertSame(self::SUMMARY_TEXT, trim($crawler->filter('.smart-brief-panel__text')->text()));
        self::assertStringContainsString(
            'Requested price',
            OpenAiSummaryClientFactoryStub::getLastClient()->getRequests()[0]->userContent,
            'The price ranges are sent to the AI.'
        );

        $summary = $crawler->filter('.widget-content.smart-brief-panel');
        self::assertCount(1, $summary);
        self::assertCount(
            1,
            $summary->filter(
                '[data-smart-brief-line="requested_products"], '
                . '[data-smart-brief-line="previously_purchased"]'
            )
        );
        self::assertCount(
            0,
            $summary->filter(
                '[data-smart-brief-line="list_gap_percent_range"], '
                . '[data-smart-brief-line="last_paid_gap_percent_range"]'
            )
        );
    }

    /**
     * The fallback state: a notice instead of the paragraph and the key facts, no age.
     */
    public function testWidgetRendersTheFallbackWhenTheAiFails(): void
    {
        OpenAiSummaryClientFactoryStub::failWith(
            new SummaryClientException('down', SummaryClientException::REASON_TIMEOUT)
        );
        $this->setConfigValue('oro_smart_brief.regenerate_enabled', true);

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        $summary = $crawler->filter('.widget-content.smart-brief-panel[data-smart-brief-fallback]');
        self::assertCount(1, $summary->filter('[data-role="smart-brief-regenerate"]'), 'The user can try again.');
        self::assertCount(1, $summary);
        self::assertSame(
            'The AI summary is not available right now; see the key facts.',
            trim($summary->filter('.smart-brief-panel__text--fallback')->text())
        );
        self::assertCount(0, $summary->filter('.smart-brief-panel__age'));
        self::assertCount(2, $summary->filter('[data-smart-brief-line]'));
    }

    /**
     * AI text is shown as escaped text, never as markup.
     */
    public function testAiTextIsEscaped(): void
    {
        OpenAiSummaryClientFactoryStub::reset();
        OpenAiSummaryClientFactoryStub::respondWith(
            new SummaryResponse('<script>alert(1)</script> Acme ordered <b>twice</b>.', 'stub-model', 10, 5)
        );

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        $text = $crawler->filter('.smart-brief-panel__text');
        self::assertSame('<script>alert(1)</script> Acme ordered <b>twice</b>.', trim($text->text()));
        self::assertCount(0, $text->filter('script, b'));
        self::assertStringContainsString('&lt;script&gt;', $this->client->getResponse()->getContent());
    }

    /**
     * The key values the prompt asks for in bold are shown as `<strong>`; nothing else of the AI text is markup.
     */
    public function testBoldValuesAreShownAsStrong(): void
    {
        OpenAiSummaryClientFactoryStub::reset();
        OpenAiSummaryClientFactoryStub::respondWith(
            new SummaryResponse('Acme has **2** open RFQs and <b>no</b> quotes.', 'stub-model', 10, 5)
        );

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        $text = $crawler->filter('.smart-brief-panel__text');
        self::assertSame(['2'], $text->filter('strong')->each(static fn ($node) => $node->text()));
        self::assertCount(0, $text->filter('b'));
        self::assertSame('Acme has 2 open RFQs and <b>no</b> quotes.', trim($text->text()));
    }

    /**
     * A customer with nothing but its name and zero counts gets the "no recent activity" note, without key
     * facts, the Regenerate button or an AI call.
     */
    public function testWidgetRendersTheNoActivityNoteWithoutInformativeFacts(): void
    {
        CustomerFactProviderStub::reset();
        $this->setConfigValue('oro_smart_brief.regenerate_enabled', true);

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        $summary = $crawler->filter('.widget-content.smart-brief-panel[data-smart-brief-no-activity]');
        self::assertCount(1, $summary);
        self::assertSame(
            'There is no recent activity to summarize.',
            trim($summary->filter('.smart-brief-panel__text--fallback')->text())
        );
        self::assertCount(0, $summary->filter('[data-smart-brief-line], [data-role="smart-brief-regenerate"]'));
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient());
    }

    public function testWidgetRendersTheMarkedEmptyWrapperWhenTheSwitchIsOff(): void
    {
        $this->setConfigValue('oro_smart_brief.customer_enabled', false);

        $crawler = $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_OK);
        self::assertCount(1, $crawler->filter('.widget-content[data-smart-brief-empty="1"]'));
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient());
    }

    public function testRouteIsGoneWhenTheFeatureIsOff(): void
    {
        $this->setConfigValue('oro_smart_brief.enabled', false);
        $this->resetFeatureCache();

        $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, $this->getCustomer()->getId());

        self::assertResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_NOT_FOUND);
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient());
    }

    public function testNonXhrRequestIsNotFound(): void
    {
        $this->client->request(
            'GET',
            $this->getUrl('oro_smart_brief_widget', [
                'entityClass' => self::CUSTOMER_CLASS_ALIAS,
                'entityId' => $this->getCustomer()->getId(),
            ])
        );

        self::assertResponseStatusCodeEquals($this->client->getResponse(), Response::HTTP_NOT_FOUND);
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient());
    }

    /**
     * @dataProvider notFoundDataProvider
     */
    public function testUnknownUnsupportedMissingAndDeniedRecordsGetTheSameNotFound(string $case): void
    {
        $customer = $this->getCustomer();
        [$entityClass, $entityId] = match ($case) {
            'unknown class' => ['Acme_Nope', $customer->getId()],
            'malformed id' => [self::CUSTOMER_CLASS_ALIAS, 'not-an-id'],
            'oversized id' => [self::CUSTOMER_CLASS_ALIAS, '99999999999999999999999'],
            'unsupported class' => ['Oro_Bundle_OrderBundle_Entity_Order', 1],
            'missing record' => [self::CUSTOMER_CLASS_ALIAS, 999999999],
            'view denied' => [self::CUSTOMER_CLASS_ALIAS, $customer->getId()],
        };

        try {
            if ('view denied' === $case) {
                $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Customer::class, AccessLevel::NONE_LEVEL);
            }
            $this->requestWidget($entityClass, $entityId);
        } finally {
            if ('view denied' === $case) {
                $this->updateRolePermissions(User::ROLE_ADMINISTRATOR, Customer::class, self::FULL_CUSTOMER_ACCESS);
            }
        }
        $response = $this->client->getResponse();

        self::assertResponseStatusCodeEquals($response, Response::HTTP_NOT_FOUND);
        self::assertNull(OpenAiSummaryClientFactoryStub::getLastClient());
        // Byte-equal bodies hold because the test env runs with debug off (no trace) and the error page prints
        // neither the request path nor the exception message, exactly like production.
        $this->requestWidget(self::CUSTOMER_CLASS_ALIAS, 999999999);
        $reference = $this->client->getResponse();
        self::assertSame($reference->headers->get('Content-Type'), $response->headers->get('Content-Type'));
        self::assertSame($reference->getContent(), $response->getContent(), 'Every refusal must look the same.');
    }

    public static function notFoundDataProvider(): array
    {
        return [
            ['unknown class'],
            ['malformed id'],
            ['oversized id'],
            ['unsupported class'],
            ['missing record'],
            ['view denied'],
        ];
    }

    private function requestWidget(string $entityClass, int|string $entityId, array $query = []): Crawler
    {
        return $this->client->request(
            'GET',
            $this->getUrl(
                'oro_smart_brief_widget',
                ['entityClass' => $entityClass, 'entityId' => $entityId] + $query
            ),
            [],
            [],
            ['HTTP_X-Requested-With' => 'XMLHttpRequest']
        );
    }

    private function getCustomer(): Customer
    {
        return $this->getReference(LoadCustomers::CUSTOMER_LEVEL_1_1);
    }

    private function resetFeatureCache(): void
    {
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
    }
}
