<?php

namespace Oro\Bundle\AiContentGenerationBundle\Client;

use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;

/**
 * Abstraction for AI Client. Provides basic methods for interaction with AI services.
 */
interface ContentGenerationClientInterface
{
    public function generateTextContent(ContentGenerationRequest $request): string;

    public function checkConnection(): void;

    public function supportsUserContentSize(): bool;
}
