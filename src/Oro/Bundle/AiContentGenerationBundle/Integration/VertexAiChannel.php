<?php

namespace Oro\Bundle\AiContentGenerationBundle\Integration;

use Oro\Bundle\IntegrationBundle\Provider\ChannelInterface;
use Oro\Bundle\IntegrationBundle\Provider\IconAwareIntegrationInterface;

/**
 * Vertex AI integration channel.
 */
class VertexAiChannel implements ChannelInterface, IconAwareIntegrationInterface
{
    public const string TYPE = 'vertex_ai';

    #[\Override]
    public function getLabel(): string
    {
        return 'oro_ai_content_generation.integration.vertex_ai.channel_type.label';
    }

    #[\Override]
    public function getIcon(): string
    {
        return 'bundles/oroaicontentgeneration/img/vertex-ai-logo.png';
    }
}
