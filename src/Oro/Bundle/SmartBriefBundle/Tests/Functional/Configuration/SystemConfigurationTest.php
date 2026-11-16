<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Configuration;

use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadOpenAiChannelData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadSmartBriefChannelsData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Component\PhpUtils\ArrayUtil;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The SmartBrief
 * section of the system and organization configuration, its fields, defaults, allowed integrations and the
 * cache lifetime validation. While the switch is off only the switch shows (the feature's configuration fields
 * are hidden, as in AI Content Generation); with the switch on and the selected channel disabled every field
 * shows. Every test runs in its own transaction.
 *
 * @dbIsolationPerTest
 */
final class SystemConfigurationTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;

    private const string FORM = 'smart_brief';
    private const array ALL_FIELDS = [
        'oro_smart_brief___enabled',
        'oro_smart_brief___integration',
        'oro_smart_brief___customer_enabled',
        'oro_smart_brief___rfq_enabled',
        'oro_smart_brief___cache_ttl',
        'oro_smart_brief___cache_invalidate_on_change',
        'oro_smart_brief___regenerate_enabled',
        'oro_smart_brief___expanded',
    ];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadSmartBriefChannelsData::class]);
    }

    public function testWhileTheSwitchIsOffOnlyTheSwitchShows(): void
    {
        $crawler = $this->requestSystemPage();

        self::assertCount(1, $this->getValueField($crawler, 'oro_smart_brief___enabled'));
        foreach (array_diff(self::ALL_FIELDS, ['oro_smart_brief___enabled']) as $field) {
            self::assertCount(0, $this->getValueField($crawler, $field), $field);
        }
        $values = $crawler->selectButton('Save settings')->form()->getPhpValues()[self::FORM];
        self::assertArrayNotHasKey('value', $values['oro_smart_brief___enabled'], 'Off by default.');
    }

    public function testSystemPageShowsTheFieldsWithTheirDefaults(): void
    {
        $this->switchOn();
        $crawler = $this->requestSystemPage();

        foreach (self::ALL_FIELDS as $field) {
            self::assertCount(1, $this->getValueField($crawler, $field), $field);
        }
        // the defaults are inherited, so the value inputs are rendered disabled with the default values
        self::assertNull($this->getValueField($crawler, 'oro_smart_brief___customer_enabled')->attr('checked'));
        self::assertNull($this->getValueField($crawler, 'oro_smart_brief___rfq_enabled')->attr('checked'));
        self::assertSame('14400', $this->getValueField($crawler, 'oro_smart_brief___cache_ttl')->attr('value'));
        self::assertNotNull(
            $this->getValueField($crawler, 'oro_smart_brief___cache_invalidate_on_change')->attr('checked')
        );
        self::assertCount(
            0,
            $this->getValueField($crawler, 'oro_smart_brief___integration')->filter('option[selected]')
                ->reduce(static fn (Crawler $option) => '' !== (string)$option->attr('value'))
        );
        self::assertStringContainsString('Refresh on Data Change', $crawler->html());
        self::assertNull($this->getValueField($crawler, 'oro_smart_brief___regenerate_enabled')->attr('checked'));
        self::assertNotNull($this->getValueField($crawler, 'oro_smart_brief___expanded')->attr('checked'));
    }

    public function testOnlyOpenAiAndVertexAiIntegrationsAreOffered(): void
    {
        $this->switchOn();
        $options = $this->getValueField($this->requestSystemPage(), 'oro_smart_brief___integration')
            ->filter('option')
            ->each(static fn (Crawler $option) => trim($option->text()));

        self::assertContains('OpenAI summaries', $options);
        self::assertContains('Vertex AI summaries', $options);
        // the other channel is enabled and of the same organization: only its type keeps it out
        self::assertTrue($this->getReference(LoadSmartBriefChannelsData::OTHER_CHANNEL)->isEnabled());
        self::assertNotContains('Another integration', $options);
    }

    /**
     * @dataProvider invalidCacheTtlDataProvider
     */
    public function testInvalidCacheLifetimeIsRejected(string $ttl): void
    {
        $configManager = self::getConfigManager();
        $configManager->set('oro_smart_brief.cache_ttl', 600);
        $configManager->flush();
        $this->switchOn();

        $crawler = $this->submitForm(
            $this->getSystemUrl(),
            ['oro_smart_brief___cache_ttl' => ['use_parent_scope_value' => false, 'value' => $ttl]]
        );

        self::assertNotEmpty(
            $crawler->filter('.validation-failed, .validation-error, .error')->count()
            + substr_count($crawler->html(), 'This value should be'),
            'The form shows a validation error.'
        );
        $configManager->reload();
        self::assertSame(600, $configManager->get('oro_smart_brief.cache_ttl'), 'The stored value is unchanged.');
    }

    public static function invalidCacheTtlDataProvider(): array
    {
        return ['zero' => ['0'], 'negative' => ['-5'], 'text' => ['abc']];
    }

    public function testValidSettingsAreSaved(): void
    {
        $this->switchOn();
        $channel = $this->getReference(LoadSmartBriefChannelsData::VERTEX_CHANNEL);

        $this->submitForm($this->getSystemUrl(), [
            'oro_smart_brief___integration' => ['use_parent_scope_value' => false, 'value' => $channel->getId()],
            'oro_smart_brief___cache_ttl' => ['use_parent_scope_value' => false, 'value' => '60'],
        ]);

        $configManager = self::getConfigManager();
        $configManager->reload();
        self::assertSame(60, $configManager->get('oro_smart_brief.cache_ttl'));
        self::assertSame($channel->getId(), $configManager->get('oro_smart_brief.integration'));
    }

    /**
     * With the switch on and the selected channel disabled the feature is off, and the settings
     * stay reachable with the integration selector shown.
     */
    public function testSettingsStayReachableWhileTheFeatureIsOff(): void
    {
        $configManager = self::getConfigManager();
        $configManager->set('oro_smart_brief.enabled', true);
        $configManager->set(
            'oro_smart_brief.integration',
            $this->getReference(LoadOpenAiChannelData::DISABLED_CHANNEL)->getId()
        );
        $configManager->flush();
        $this->resetFeatureCache();
        self::assertFalse(
            self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->isFeatureEnabled('oro_smart_brief'),
            'A disabled channel turns the feature off.'
        );
        // that check ran outside a configuration page; do not let its cached result decide the page
        $this->resetFeatureCache();

        $crawler = $this->requestSystemPage();

        self::assertCount(1, $this->getValueField($crawler, 'oro_smart_brief___integration'));
        self::assertCount(1, $this->getValueField($crawler, 'oro_smart_brief___customer_enabled'));
    }

    public function testOrganizationPageShowsOnlyTheOrganizationFields(): void
    {
        $this->skipWithoutOrganizationConfiguration();
        $this->switchOn();
        $organization = $this->getReference(LoadOrganization::ORGANIZATION);
        $crawler = $this->client->request('GET', $this->getOrganizationUrl($organization->getId()));
        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        $fields = ['enabled', 'integration', 'customer_enabled', 'rfq_enabled', 'regenerate_enabled', 'expanded'];
        foreach ($fields as $field) {
            self::assertCount(1, $this->getValueField($crawler, 'oro_smart_brief___' . $field), $field);
        }
        foreach (['cache_ttl', 'cache_invalidate_on_change'] as $field) {
            self::assertCount(0, $this->getValueField($crawler, 'oro_smart_brief___' . $field), $field);
        }
    }

    public function testOrganizationValuesOverrideTheGlobalOnes(): void
    {
        $this->skipWithoutOrganizationConfiguration();
        $this->switchOn();
        $organization = $this->getReference(LoadOrganization::ORGANIZATION);

        $this->submitForm($this->getOrganizationUrl($organization->getId()), [
            'oro_smart_brief___customer_enabled' => ['use_parent_scope_value' => false, 'value' => '1'],
        ]);

        $organizationConfig = self::getConfigManager('organization');
        $organizationConfig->reload();
        self::assertTrue(
            (bool)$organizationConfig->get('oro_smart_brief.customer_enabled', false, false, $organization->getId())
        );
        $globalConfig = self::getConfigManager('global');
        $globalConfig->reload();
        self::assertFalse((bool)$globalConfig->get('oro_smart_brief.customer_enabled'), 'The global value is kept.');
    }

    public function testUserPageShowsOnlyTheExpandByDefaultField(): void
    {
        $this->switchOn();
        // with an enabled integration selected, the feature is on for the user
        $configManager = self::getConfigManager();
        $configManager->set(
            'oro_smart_brief.integration',
            $this->getReference(LoadSmartBriefChannelsData::VERTEX_CHANNEL)->getId()
        );
        $configManager->flush();
        $this->resetFeatureCache();

        $crawler = $this->client->request('GET', $this->getUrl('oro_user_profile_configuration', [
            'activeGroup' => 'platform',
            'activeSubGroup' => 'look_and_feel',
        ]));

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);
        self::assertCount(1, $crawler->filter('[name="look_and_feel[oro_smart_brief___expanded][value]"]'));
        self::assertSame(
            ['oro_smart_brief___expanded'],
            array_values(array_unique($crawler->filter('[name*="[oro_smart_brief___"]')->each(
                static fn (Crawler $node) => preg_replace('/^[^\[]+\[([^\]]+)\].*$/', '$1', $node->attr('name'))
            )))
        );
    }

    public function testUserPageHidesTheFieldWhileTheFeatureIsOff(): void
    {
        $this->client->request('GET', $this->getUrl('oro_user_profile_configuration', [
            'activeGroup' => 'platform',
            'activeSubGroup' => 'look_and_feel',
        ]));

        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);
        self::assertStringNotContainsString('oro_smart_brief___', (string)$this->client->getResponse()->getContent());
    }

    /**
     * The organization configuration exists only in the enterprise editions (OroOrganizationConfigBundle).
     */
    private function skipWithoutOrganizationConfiguration(): void
    {
        if (null === self::getContainer()->get('router')->getRouteCollection()->get('oro_organization_config')) {
            self::markTestSkipped('The organization configuration is not available in this edition.');
        }
    }

    private function switchOn(): void
    {
        $configManager = self::getConfigManager();
        $configManager->set('oro_smart_brief.enabled', true);
        $configManager->flush();
        $this->resetFeatureCache();
    }

    private function resetFeatureCache(): void
    {
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
    }

    private function getSystemUrl(): string
    {
        return $this->getUrl('oro_config_configuration_system', [
            'activeGroup' => 'platform',
            'activeSubGroup' => 'smart_brief',
        ]);
    }

    private function getOrganizationUrl(int $organizationId): string
    {
        return $this->getUrl('oro_organization_config', [
            'id' => $organizationId,
            'activeGroup' => 'platform',
            'activeSubGroup' => 'smart_brief',
        ]);
    }

    private function requestSystemPage(): Crawler
    {
        $crawler = $this->client->request('GET', $this->getSystemUrl());
        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        return $crawler;
    }

    private function submitForm(string $url, array $values): Crawler
    {
        $form = $this->client->request('GET', $url)->selectButton('Save settings')->form();
        $data = ArrayUtil::arrayMergeRecursiveDistinct(
            $form->getPhpValues(),
            [self::FORM => array_merge($values, ['_token' => $this->getCsrfToken(self::FORM)->getValue()])]
        );
        $crawler = $this->client->request($form->getMethod(), $form->getUri(), $data);
        self::assertHtmlResponseStatusCodeEquals($this->client->getResponse(), 200);

        return $crawler;
    }

    private function getValueField(Crawler $crawler, string $field): Crawler
    {
        return $crawler->filter(sprintf('[name^="%s[%s][value]"]', self::FORM, $field));
    }
}
