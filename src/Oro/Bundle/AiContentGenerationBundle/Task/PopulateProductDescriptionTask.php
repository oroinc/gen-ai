<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Generates product description based on full context
 */
class PopulateProductDescriptionTask extends AbstractProductTask implements TaskInterface
{
    #[\Override]
    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if (!parent::supports($contentGenerationRequest)) {
            return false;
        }

        return !empty($this->getContext($contentGenerationRequest));
    }

    #[\Override] public function getKey(): string
    {
        return 'populate_product_description';
    }

    #[\Override] public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return $this->productContextProvider->getFullContext(
            $contentGenerationRequest,
            $this->pluralForm
        );
    }
}
