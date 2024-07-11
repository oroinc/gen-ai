<?php

namespace Oro\Bundle\AiContentGenerationBundle\Integration;

use Oro\Bundle\IntegrationBundle\Provider\ChannelInterface;
use Oro\Bundle\IntegrationBundle\Provider\IconAwareIntegrationInterface;

/**
 * OpenAI integration channel.
 */
class OpenAiChannel implements ChannelInterface, IconAwareIntegrationInterface
{
    public const TYPE = 'open_ai';

    /**
     * {@inheritDoc}
     */
    public function getLabel(): string
    {
        return 'oro_ai_content_generation.integration.open_ai.channel_type.label';
    }

    /**
     * {@inheritDoc}
     */
    public function getIcon(): string
    {
        return 'bundles/oroaicontentgeneration/img/open-ai-logo.png';
    }
}
