<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Client;

use Oro\Bundle\AiContentGenerationBundle\Client\OpenAiMessage;
use PHPUnit\Framework\TestCase;

final class OpenAiMessageTest extends TestCase
{
    public function testStaticCreateSystemMessage(): void
    {
        $openAiMessage = OpenAiMessage::fromSystem('content');

        self::assertEquals('content', $openAiMessage->getContent());
        self::assertFalse($openAiMessage->isAssistant());
        self::assertEquals(['role' => 'system', 'content' => 'content'], $openAiMessage->toArray());
    }

    public function testStaticCreateUserMessage(): void
    {
        $openAiMessage = OpenAiMessage::fromUser('content');

        self::assertEquals('content', $openAiMessage->getContent());
        self::assertFalse($openAiMessage->isAssistant());
        self::assertEquals(['role' => 'user', 'content' => 'content'], $openAiMessage->toArray());
    }

    public function testStaticCreateAssistantMessage(): void
    {
        $openAiMessage = OpenAiMessage::fromAssistant('content');

        self::assertEquals('content', $openAiMessage->getContent());
        self::assertTrue($openAiMessage->isAssistant());
        self::assertEquals(['role' => 'assistant', 'content' => 'content'], $openAiMessage->toArray());
    }
}
