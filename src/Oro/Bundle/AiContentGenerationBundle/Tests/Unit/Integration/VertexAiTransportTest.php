<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Integration;

use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\VertexAiTransportSettingsType;
use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiTransport;
use PHPUnit\Framework\TestCase;

final class VertexAiTransportTest extends TestCase
{
    private VertexAiTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new VertexAiTransport();
    }

    public function testGetSettingsFormType(): void
    {
        self::assertEquals(VertexAiTransportSettingsType::class, $this->transport->getSettingsFormType());
    }

    public function testGetSettingsEntityFQCN(): void
    {
        self::assertEquals(VertexAiTransportSettings::class, $this->transport->getSettingsEntityFQCN());
    }

    public function testGetLabelReturnsString(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.integration.vertex_ai.settings.label',
            $this->transport->getLabel()
        );
    }
}
