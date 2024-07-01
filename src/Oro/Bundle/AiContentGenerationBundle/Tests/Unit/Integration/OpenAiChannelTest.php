<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Integration;

use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use PHPUnit\Framework\TestCase;

final class OpenAiChannelTest extends TestCase
{
    private OpenAiChannel $channel;

    protected function setUp(): void
    {
        $this->channel = new OpenAiChannel();
    }

    public function testGetLabel(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.integration.open_ai.channel_type.label',
            $this->channel->getLabel()
        );
    }

    public function testGetIcon(): void
    {
        self::assertEquals('bundles/oroaicontentgeneration/img/open-ai-logo.png', $this->channel->getIcon());
    }
}
