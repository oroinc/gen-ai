<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Integration;

use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel;
use PHPUnit\Framework\TestCase;

final class VertexAiChannelTest extends TestCase
{
    private VertexAiChannel $channel;

    #[\Override]
    protected function setUp(): void
    {
        $this->channel = new VertexAiChannel();
    }

    public function testGetLabel(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.integration.vertex_ai.channel_type.label',
            $this->channel->getLabel()
        );
    }

    public function testGetIcon(): void
    {
        self::assertEquals('bundles/oroaicontentgeneration/img/vertex-ai-logo.png', $this->channel->getIcon());
    }
}
