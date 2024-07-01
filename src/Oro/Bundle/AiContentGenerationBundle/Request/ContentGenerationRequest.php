<?php

namespace Oro\Bundle\AiContentGenerationBundle\Request;

/**
 * Holds information needed for AI Client to process user request
 */
class ContentGenerationRequest
{
    private readonly int $maxTokens;

    public function __construct(
        private readonly string $taskGenerationPhrase,
        private readonly array $context,
        private readonly string $tone,
    ) {
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getClientPrompt(): string
    {
        return sprintf('%s with tone %s', $this->getTaskGenerationPhrase(), $this->getTone());
    }

    public function getClientContext(): string
    {
        $contextLines = [];

        foreach ($this->getContext() as $title => $value) {
            $contextLines[] = sprintf('%s:%s', $title, strip_tags($value));
        }

        $context = implode("\n", $contextLines);

        return html_entity_decode(
            sprintf("Context: \n%s", $context)
        );
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function getTone(): string
    {
        return $this->tone;
    }

    public function getTaskGenerationPhrase(): string
    {
        return $this->taskGenerationPhrase;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
