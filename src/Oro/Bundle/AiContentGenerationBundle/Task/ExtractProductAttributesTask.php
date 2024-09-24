<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Extract product attributes from the description
 */
class ExtractProductAttributesTask extends AbstractProductTask implements TaskInterface
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
        return 'extract_product_attributes';
    }

    #[\Override] public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return array_filter([
            $this->productContextProvider->getDescription($contentGenerationRequest, $this->pluralForm)
        ]);
    }
}
