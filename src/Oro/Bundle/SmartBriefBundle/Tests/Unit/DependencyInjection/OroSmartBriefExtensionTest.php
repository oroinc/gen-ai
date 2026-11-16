<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\DependencyInjection;

use Oro\Bundle\SmartBriefBundle\DependencyInjection\OroSmartBriefExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OroSmartBriefExtensionTest extends TestCase
{
    public function testLoad(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');

        $extension = new OroSmartBriefExtension();
        $extension->load([], $container);

        self::assertSame(
            [
                [
                    'settings' => [
                        'resolved' => true,
                        'enabled' => ['value' => false, 'scope' => 'app'],
                        'integration' => ['value' => null, 'scope' => 'app'],
                        'customer_enabled' => ['value' => false, 'scope' => 'app'],
                        'rfq_enabled' => ['value' => false, 'scope' => 'app'],
                        'cache_ttl' => ['value' => 14400, 'scope' => 'app'],
                        'cache_invalidate_on_change' => ['value' => true, 'scope' => 'app'],
                        'regenerate_enabled' => ['value' => false, 'scope' => 'app'],
                        'expanded' => ['value' => true, 'scope' => 'app'],
                    ],
                ],
            ],
            $container->getExtensionConfig('oro_smart_brief')
        );

        self::assertTrue($container->hasDefinition('oro_smart_brief.feature.feature_voter'));
        $voterDefinition = $container->getDefinition('oro_smart_brief.feature.feature_voter');
        self::assertTrue($voterDefinition->hasTag('oro_featuretogle.voter'));
        self::assertSame(
            ['oro_config_configuration_system', 'oro_organization_config', 'oro_user_config'],
            $voterDefinition->getArgument(3)
        );

        self::assertTrue($container->hasDefinition('oro_smart_brief.fact_provider.registry'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.fact_value_type.registry'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.provider.calendar_date'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.provider.calendar_date'));
        self::assertFalse($container->hasAlias('oro_smart_brief.tests.provider.calendar_date'));
        self::assertFalse($container->hasAlias('oro_smart_brief.tests.client.factory'));
        self::assertFalse($container->hasDefinition('oro_smart_brief.tests.factory.open_ai_client_factory'));
        self::assertFalse($container->hasDefinition('oro_smart_brief.tests.fact_provider.customer'));

        $pool = $container->getDefinition('oro_smart_brief.cache');
        self::assertSame('oro.data.cache', $pool->getParent());
        self::assertSame([['namespace' => 'oro_smart_brief']], $pool->getTag('cache.pool'));
        self::assertSame(
            ['oro_smart_brief.factory.open_ai_client_factory', 'oro_smart_brief.factory.vertex_ai_client_factory'],
            array_keys($container->findTaggedServiceIds('oro_smart_brief.client_factory'))
        );
        self::assertTrue($container->hasDefinition('oro_smart_brief.client.factory'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.provider.selected_integration_channel'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.generator'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.generator.provider_resolver'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.generator.text_post_processor'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.generator.text_generator'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.placeholder.filter'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.twig.extension'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.fact_provider.customer'));
        // built-in providers are tagged with an explicit priority a project can outrank
        foreach (['oro_smart_brief.fact_provider.customer', 'oro_smart_brief.fact_provider.rfq'] as $providerId) {
            self::assertSame(
                [['priority' => 0]],
                $container->getDefinition($providerId)->getTag('oro_smart_brief.fact_provider'),
                $providerId
            );
        }
        self::assertTrue($container->hasDefinition('oro_smart_brief.provider.summary_access'));
        self::assertTrue($container->getDefinition('oro_smart_brief.placeholder.filter')->isPublic());
        self::assertTrue(
            $container->hasDefinition('Oro\\Bundle\\SmartBriefBundle\\Controller\\SummaryWidgetController')
        );
        self::assertTrue($container->hasDefinition('oro_smart_brief.formatter.fact'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.prompt.request_builder'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.serializer.fact_set'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.cache.key_builder'));
        self::assertTrue($container->hasDefinition('oro_smart_brief.cache.summary'));
        self::assertSame(
            ['date', 'integer', 'percent', 'money', 'text', 'enum_option', 'choice'],
            array_values(array_map(
                static fn (string $id) => substr($id, \strlen('oro_smart_brief.fact_value_type.')),
                array_keys($container->findTaggedServiceIds('oro_smart_brief.fact_value_type'))
            ))
        );
    }

    public function testLoadInTestEnvironmentAddsPublicAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new OroSmartBriefExtension())->load([], $container);

        self::assertTrue($container->hasAlias('oro_smart_brief.tests.provider.calendar_date'));
        self::assertTrue($container->getAlias('oro_smart_brief.tests.provider.calendar_date')->isPublic());
        self::assertTrue($container->hasAlias('oro_smart_brief.tests.client.factory'));
        self::assertTrue($container->getAlias('oro_smart_brief.tests.client.factory')->isPublic());
        self::assertTrue($container->hasAlias('oro_smart_brief.tests.generator'));
        self::assertTrue($container->getAlias('oro_smart_brief.tests.generator')->isPublic());
        self::assertSame(
            ['oro_smart_brief.fact_provider.customer', 'oro_smart_brief.tests.fact_provider.customer.inner', 0],
            $container->getDefinition('oro_smart_brief.tests.fact_provider.customer')->getDecoratedService()
        );
        self::assertSame(
            ['oro_smart_brief.factory.open_ai_client_factory', null, 0],
            $container->getDefinition('oro_smart_brief.tests.factory.open_ai_client_factory')->getDecoratedService()
        );
        self::assertSame(
            ['oro_smart_brief.factory.vertex_ai_client_factory', null, 0],
            $container->getDefinition('oro_smart_brief.tests.factory.vertex_ai_client_factory')->getDecoratedService()
        );
    }

    /**
     * @dataProvider parametersDataProvider
     */
    public function testLoadParameters(string $name, mixed $expectedValue): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'prod');

        $extension = new OroSmartBriefExtension();
        $extension->load([], $container);

        self::assertSame($expectedValue, $container->getParameter($name));
    }

    public static function parametersDataProvider(): array
    {
        return [
            ['oro_smart_brief.client.temperature', 0.2],
            ['oro_smart_brief.client.max_output_tokens', 1024],
            ['oro_smart_brief.client.timeout', 10],
            ['oro_smart_brief.client.reasoning_effort', 'low'],
            ['oro_smart_brief.client.thinking_budget', 0],
            ['oro_smart_brief.text_max_length', 200],
        ];
    }
}
