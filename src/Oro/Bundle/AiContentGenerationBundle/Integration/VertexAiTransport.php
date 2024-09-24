<?php

namespace Oro\Bundle\AiContentGenerationBundle\Integration;

use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\VertexAiTransportSettingsType;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Oro\Bundle\IntegrationBundle\Provider\TransportInterface;

/**
 * Basic Vertex AI integration transport configuration.
 */
class VertexAiTransport implements TransportInterface
{
    #[\Override]
    public function init(Transport $transportEntity): void
    {
    }

    #[\Override]
    public function getLabel(): string
    {
        return 'oro_ai_content_generation.integration.vertex_ai.settings.label';
    }

    #[\Override]
    public function getSettingsFormType(): string
    {
        return VertexAiTransportSettingsType::class;
    }

    #[\Override]
    public function getSettingsEntityFQCN(): string
    {
        return VertexAiTransportSettings::class;
    }
}
