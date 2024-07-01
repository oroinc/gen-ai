<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Incorporate product attributes to provided description text
 */
class IncorporateProductAttributesToDescriptionTask extends AbstractProductTask implements TaskInterface
{
    public function __construct(
        ProductTaskContextProvider $productContextProvider,
        string $longDescriptionPluralForm = 'descriptions'
    ) {
        parent::__construct($productContextProvider, $longDescriptionPluralForm);
    }

    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if (!parent::supports($contentGenerationRequest)) {
            return false;
        }

        $contextItems = $this->getContext($contentGenerationRequest);

        return count($contextItems) > 1;
    }

    public function getKey(): string
    {
        return 'incorporate_product_attributes_to_description';
    }

    public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return array_filter([
            $this->productContextProvider->getDescription($contentGenerationRequest, $this->pluralForm),
            ...$this->productContextProvider->getAttributes($contentGenerationRequest)
        ]);
    }
}
