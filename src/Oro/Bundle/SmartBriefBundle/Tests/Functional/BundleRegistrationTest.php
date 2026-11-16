<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\OroSmartBriefBundle;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BundleRegistrationTest extends WebTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->initClient();
    }

    public function testBundleIsRegistered(): void
    {
        self::assertContains(
            OroSmartBriefBundle::class,
            self::getContainer()->getParameter('kernel.bundles')
        );
    }

    public function testConfigurationDefaults(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = self::getContainer()->get('oro_config.global');

        self::assertFalse($configManager->get('oro_smart_brief.enabled'));
        self::assertNull($configManager->get('oro_smart_brief.integration'));
        self::assertFalse($configManager->get('oro_smart_brief.customer_enabled'));
        self::assertFalse($configManager->get('oro_smart_brief.rfq_enabled'));
        self::assertSame(14400, $configManager->get('oro_smart_brief.cache_ttl'));
        self::assertTrue($configManager->get('oro_smart_brief.cache_invalidate_on_change'));
    }

    public function testContainerParameters(): void
    {
        $container = self::getContainer();

        self::assertSame(10, $container->getParameter('oro_smart_brief.client.timeout'));
        self::assertSame(200, $container->getParameter('oro_smart_brief.text_max_length'));
    }

    public function testFeatureIsKnownAndDisabledByDefault(): void
    {
        /** @var FeatureChecker $featureChecker */
        $featureChecker = self::getContainer()->get('oro_featuretoggle.checker.feature_checker');

        self::assertFalse($featureChecker->isFeatureEnabled('oro_smart_brief'));
    }

    public function testFeatureTranslations(): void
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        self::assertSame('AI Smart Brief', $translator->trans('oro_smart_brief.feature.label', [], null, 'en'));
        self::assertNotSame(
            'oro_smart_brief.feature.description',
            $translator->trans('oro_smart_brief.feature.description', [], null, 'en')
        );
    }
}
