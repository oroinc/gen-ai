<?php

namespace Oro\Bundle\AiContentGenerationBundle\Integration;

use Oro\Bundle\IntegrationBundle\Provider\ChannelInterface;
use Oro\Bundle\IntegrationBundle\Provider\IconAwareIntegrationInterface;

/**
 * OpenAI integration channel.
 */
class OpenAiChannel implements ChannelInterface, IconAwareIntegrationInterface
{
    public const string TYPE = 'open_ai';

    #[\Override]
    public function getLabel(): string
    {
        return 'oro_ai_content_generation.integration.open_ai.channel_type.label';
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'bundles/oroaicontentgeneration/img/open-ai-logo.png';
    }
}
