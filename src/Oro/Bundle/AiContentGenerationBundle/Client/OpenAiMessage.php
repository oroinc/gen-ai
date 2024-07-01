<?php

namespace Oro\Bundle\AiContentGenerationBundle\Client;

/**
 * Represents OpenAI Message structure
 */
class OpenAiMessage
{
    public function __construct(
        private readonly string $role,
        private readonly string $content
    ) {
    }

    public static function fromSystem(string $content): self
    {
        return new self('system', $content);
    }

    public static function fromUser(string $content): self
    {
        return new self('user', $content);
    }

    public static function fromAssistant(string $content): self
    {
        return new self('assistant', $content);
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
