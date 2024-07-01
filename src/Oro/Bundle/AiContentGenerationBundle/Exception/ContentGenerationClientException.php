<?php

namespace Oro\Bundle\AiContentGenerationBundle\Exception;

/**
 * Indicates an error with AI Client
 */
class ContentGenerationClientException extends \Exception
{
    public static function clientConnection(string $clientName, ?\Throwable $originalException = null): self
    {
        return new self(
            sprintf('Connection with %s cannot be established', $clientName),
            previous: $originalException
        );
    }
}
