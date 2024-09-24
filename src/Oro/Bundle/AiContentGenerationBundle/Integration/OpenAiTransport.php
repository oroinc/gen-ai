<?php

namespace Oro\Bundle\AiContentGenerationBundle\Integration;

use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\OpenAiTransportSettingsType;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Oro\Bundle\IntegrationBundle\Provider\TransportInterface;

/**
 * Basic OpenAI integration transport configuration.
 */
class OpenAiTransport implements TransportInterface
{
    #[\Override]
    public function init(Transport $transportEntity): void
    {
    }

    #[\Override]
    public function getLabel(): string
    {
        return 'oro_ai_content_generation.integration.open_ai.settings.label';
    }

    #[\Override]
    public function getSettingsFormType(): string
    {
        return OpenAiTransportSettingsType::class;
    }

    #[\Override]
    public function getSettingsEntityFQCN(): string
    {
        return OpenAiTransportSettings::class;
    }
}
