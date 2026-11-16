<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Placeholder;

use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Tests\Functional\DataFixtures\LoadCustomers;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\RFPBundle\Tests\Functional\DataFixtures\LoadRequestData;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadOpenAiChannelData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\CustomerFactProviderStub;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the block placeholder on the Customer and RFQ view pages. The Customer facts
 * come from the switchable CustomerFactProviderStub presentation fixture. The RFQ page shows the block only while
 * `oro_smart_brief.rfq_enabled` is on.
 */
final class SmartBriefPlaceholderTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomers::class, LoadRequestData::class, LoadOpenAiChannelData::class]);
        $this->enableFeature();
        $this->setConfigValue('oro_smart_brief.customer_enabled', true);
        CustomerFactProviderStub::enable();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CustomerFactProviderStub::reset();
        $this->restoreConfigValues();
        $this->resetFeatureCache();
        parent::tearDown();
    }

    public function testBlockIsRenderedAfterTheTitleOfAnEligibleCustomer(): void
    {
        $customer = $this->getCustomer();

        $crawler = $this->requestCustomerPage($customer);

        $block = $crawler->filter('.smart-brief');
        self::assertCount(1, $block);
        self::assertSame((string)$customer->getId(), $block->attr('data-smart-brief-entity-id'));
        self::assertSame(
            'Oro_Bundle_CustomerBundle_Entity_Customer',
            $block->attr('data-smart-brief-entity-class')
        );
        $afterTitle = '//h1[contains(@class, "page-title__entity-title")]/following::div[@class="smart-brief"]';
        self::assertCount(1, $crawler->filterXPath($afterTitle), 'The block follows the page title.');
    }

    /**
     * The loading state: the block is a busy, polite live region showing the translated
     * writing text until its own widget type answers.
     */
    public function testBlockCarriesTheLoadingStateAndItsWidgetType(): void
    {
        $crawler = $this->requestCustomerPage($this->getCustomer());

        $block = $crawler->filter('.smart-brief');
        self::assertSame('polite', $block->attr('aria-live'));
        self::assertSame('true', $block->attr('aria-busy'));
        $writing = $block->filter('[data-role="smart-brief-writing"]');
        self::assertCount(1, $writing);
        self::assertStringContainsString('Writing the summary', $writing->text());
        $widgetOptions = (string)$block->filter('[data-page-component-module]')->attr('data-page-component-options');
        self::assertStringContainsString('"type":"smart-brief"', $widgetOptions);
        self::assertStringContainsString('"loadingMaskEnabled":false', $widgetOptions);
    }

    public function testBlockIsAbsentWhenTheUserMayNotViewTheCustomer(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Customer::class, AccessLevel::NONE_LEVEL);
        try {
            $this->client->request(
                'GET',
                $this->getUrl('oro_customer_customer_view', ['id' => $this->getCustomer()->getId()])
            );
            $response = $this->client->getResponse();
        } finally {
            // The whole ACE is replaced by the helper, so every permission is restored, not only VIEW.
            $this->updateRolePermissions(User::ROLE_ADMINISTRATOR, Customer::class, [
                'VIEW' => AccessLevel::SYSTEM_LEVEL,
                'CREATE' => AccessLevel::SYSTEM_LEVEL,
                'EDIT' => AccessLevel::SYSTEM_LEVEL,
                'DELETE' => AccessLevel::SYSTEM_LEVEL,
                'ASSIGN' => AccessLevel::SYSTEM_LEVEL,
            ]);
        }

        // The page itself is ACL-protected (403); the placeholder must not have rendered anything either way.
        self::assertResponseStatusCodeEquals($response, 403);
        self::assertStringNotContainsString('smart-brief', $response->getContent());
    }

    public function testBlockIsAbsentWhenTheCustomerSwitchIsOff(): void
    {
        $this->setConfigValue('oro_smart_brief.customer_enabled', false);

        $crawler = $this->requestCustomerPage($this->getCustomer());

        self::assertCount(0, $crawler->filter('.smart-brief'));
    }

    public function testBlockIsAbsentWhenTheFeatureIsOff(): void
    {
        $this->setConfigValue('oro_smart_brief.enabled', false);
        $this->resetFeatureCache();

        $crawler = $this->requestCustomerPage($this->getCustomer());

        self::assertCount(0, $crawler->filter('.smart-brief'));
    }

    public function testBlockIsAbsentWithoutAnEnabledIntegration(): void
    {
        $this->setConfigValue('oro_smart_brief.integration', null);
        $this->resetFeatureCache();

        $crawler = $this->requestCustomerPage($this->getCustomer());

        self::assertCount(0, $crawler->filter('.smart-brief'));
    }

    public function testBlockIsRenderedOnAnRfqPageWhenRfqSummariesAreOn(): void
    {
        $this->setConfigValue('oro_smart_brief.rfq_enabled', true);
        $this->resetFeatureCache();
        /** @var Request $request */
        $request = $this->getReference(LoadRequestData::REQUEST1);

        $block = $this->requestRfqPage($request)->filter('.smart-brief');

        self::assertCount(1, $block);
        self::assertSame((string)$request->getId(), $block->attr('data-smart-brief-entity-id'));
        self::assertSame('Oro_Bundle_RFPBundle_Entity_Request', $block->attr('data-smart-brief-entity-class'));
    }

    public function testBlockIsAbsentOnAnRfqPageWhileRfqSummariesAreOff(): void
    {
        $this->setConfigValue('oro_smart_brief.rfq_enabled', false);
        $this->resetFeatureCache();

        $crawler = $this->requestRfqPage($this->getReference(LoadRequestData::REQUEST1));

        self::assertCount(0, $crawler->filter('.smart-brief'));
    }

    private function requestRfqPage(Request $request): Crawler
    {
        $crawler = $this->client->request('GET', $this->getUrl('oro_rfp_request_view', ['id' => $request->getId()]));
        $this->assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        return $crawler;
    }

    private function requestCustomerPage(Customer $customer): Crawler
    {
        $crawler = $this->client->request(
            'GET',
            $this->getUrl('oro_customer_customer_view', ['id' => $customer->getId()])
        );
        $this->assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        return $crawler;
    }

    private function getCustomer(): Customer
    {
        return $this->getReference(LoadCustomers::CUSTOMER_LEVEL_1_1);
    }

    private function enableFeature(): void
    {
        $this->setConfigValue('oro_smart_brief.enabled', true);
        $this->setConfigValue(
            'oro_smart_brief.integration',
            $this->getReference(LoadOpenAiChannelData::CHANNEL)->getId()
        );
        $this->resetFeatureCache();
    }

    private function resetFeatureCache(): void
    {
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
    }
}
