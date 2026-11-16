<?php

namespace Oro\Bundle\SmartBriefBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Oro\Bundle\ConfigBundle\Utils\TreeUtils;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Contains system configuration options for the SmartBriefBundle.
 */
class Configuration implements ConfigurationInterface
{
    public const string ROOT_NODE = 'oro_smart_brief';

    public const string ENABLED_KEY = 'enabled';
    public const string INTEGRATION_KEY = 'integration';
    public const string CUSTOMER_ENABLED_KEY = 'customer_enabled';
    public const string RFQ_ENABLED_KEY = 'rfq_enabled';
    public const string CACHE_TTL_KEY = 'cache_ttl';
    public const string CACHE_INVALIDATE_ON_CHANGE_KEY = 'cache_invalidate_on_change';
    public const string REGENERATE_ENABLED_KEY = 'regenerate_enabled';
    public const string EXPANDED_KEY = 'expanded';

    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append($rootNode, [
            self::ENABLED_KEY => ['value' => false, 'type' => 'boolean'],
            self::INTEGRATION_KEY => ['value' => null, 'type' => 'integer'],
            self::CUSTOMER_ENABLED_KEY => ['value' => false, 'type' => 'boolean'],
            self::RFQ_ENABLED_KEY => ['value' => false, 'type' => 'boolean'],
            self::CACHE_TTL_KEY => ['value' => 14400, 'type' => 'integer'],
            self::CACHE_INVALIDATE_ON_CHANGE_KEY => ['value' => true, 'type' => 'boolean'],
            self::REGENERATE_ENABLED_KEY => ['value' => false, 'type' => 'boolean'],
            self::EXPANDED_KEY => ['value' => true, 'type' => 'boolean'],
        ]);

        return $treeBuilder;
    }

    public static function getConfigKeyByName(string $name): string
    {
        return TreeUtils::getConfigKey(self::ROOT_NODE, $name);
    }
}
