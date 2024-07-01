<?php

namespace Oro\Bundle\AiContentGenerationBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Oro\Bundle\ConfigBundle\Utils\TreeUtils;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Contains configuration options for the AiContentGenerationBundle
 */
class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'oro_ai_content_generation';

    public const ENABLE_AI_CONTENT_GENERATION_KEY = 'enable_ai_content_generation';
    public const GENERATOR_TYPE_KEY = 'generator_type';

    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append($rootNode, [
            static::ENABLE_AI_CONTENT_GENERATION_KEY => ['value' => false, 'type' => 'boolean'],
            static::GENERATOR_TYPE_KEY => ['value' => null, 'type' => 'integer'],
        ]);

        return $treeBuilder;
    }

    public static function getConfigKeyByName(string $name): string
    {
        return TreeUtils::getConfigKey(static::ROOT_NODE, $name);
    }
}
