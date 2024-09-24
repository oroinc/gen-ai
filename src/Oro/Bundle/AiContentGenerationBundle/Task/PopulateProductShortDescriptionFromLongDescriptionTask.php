<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Generates product short description based on long description
 */
class PopulateProductShortDescriptionFromLongDescriptionTask extends AbstractProductTask implements
    TaskInterface
{
    private const string SHORT_DESCRIPTION_FIELD_PLURAL_FORM = 'shortDescriptions';

    public function __construct(
        ProductTaskContextProvider $productContextProvider,
        private string $longDescriptionPluralForm
    ) {
        parent::__construct($productContextProvider, self::SHORT_DESCRIPTION_FIELD_PLURAL_FORM);
    }

    #[\Override]
    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if (!parent::supports($contentGenerationRequest)) {
            return false;
        }

        return (bool)$this->getContext($contentGenerationRequest);
    }

    #[\Override] public function getKey(): string
    {
        return 'populate_short_description_from_long_description';
    }

    #[\Override] public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return array_filter([
            $this->productContextProvider->getDescription(
                $contentGenerationRequest,
                $this->longDescriptionPluralForm,
                $this->pluralForm
            )
        ]);
    }
}
