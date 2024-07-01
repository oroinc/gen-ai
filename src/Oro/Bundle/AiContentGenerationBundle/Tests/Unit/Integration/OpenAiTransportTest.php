<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Integration;

use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\OpenAiTransportSettingsType;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiTransport;
use PHPUnit\Framework\TestCase;

final class OpenAiTransportTest extends TestCase
{
    private OpenAiTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new OpenAiTransport();
    }

    public function testGetSettingsFormType(): void
    {
        self::assertEquals(OpenAiTransportSettingsType::class, $this->transport->getSettingsFormType());
    }

    public function testGetSettingsEntityFQCN(): void
    {
        self::assertEquals(OpenAiTransportSettings::class, $this->transport->getSettingsEntityFQCN());
    }

    public function testGetLabelReturnsString(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.integration.open_ai.settings.label',
            $this->transport->getLabel()
        );
    }
}
