<?php

namespace Oro\Bundle\AiContentGenerationBundle\Context;

/**
 * Represents AI Content Generation Request context item
 */
readonly class ContextItem
{
    public function __construct(
        private string $key,
        private string|int $value
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): int|string
    {
        return $this->value;
    }

    public function getTextRepresentation(): string
    {
        return strtolower($this->getKey()) . ' ' . $this->getValue();
    }
}
