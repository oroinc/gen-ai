<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\DependencyInjection;

use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testGetConfigTreeBuilderDefaults(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), []);

        self::assertSame(
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
            $config
        );
    }

    public function testGetConfigKeyByName(): void
    {
        self::assertSame('oro_smart_brief.enabled', Configuration::getConfigKeyByName(Configuration::ENABLED_KEY));
        self::assertSame(
            'oro_smart_brief.cache_ttl',
            Configuration::getConfigKeyByName(Configuration::CACHE_TTL_KEY)
        );
    }
}
