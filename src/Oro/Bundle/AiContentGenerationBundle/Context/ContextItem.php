<?php

namespace Oro\Bundle\AiContentGenerationBundle\Context;

/**
 * Represents AI Content Generation Request context item
 */
class ContextItem
{
    public function __construct(
        private readonly string $key,
        private readonly string|int $value
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
