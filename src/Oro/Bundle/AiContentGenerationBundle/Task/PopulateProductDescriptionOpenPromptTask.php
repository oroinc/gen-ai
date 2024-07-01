<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates product description based on open prompt with predefined form content
 */
class PopulateProductDescriptionOpenPromptTask extends AbstractProductTask implements OpenPromptTaskInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        ProductTaskContextProvider $productContextProvider,
        string $pluralForm
    ) {
        parent::__construct($productContextProvider, $pluralForm);
    }

    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if (!parent::supports($contentGenerationRequest)) {
            return false;
        }

        return !empty($this->getFormPredefinedContent($contentGenerationRequest));
    }

    #[\Override] public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        $content = $contentGenerationRequest->getSubmittedContentGenerationFormData()['content'];

        if (!$content) {
            return [];
        }

        return [
            new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.global.features.label'),
                $content
            )
        ];
    }

    #[\Override] public function getKey(): string
    {
        return 'populate_product_description_with_open_prompt';
    }

    #[\Override] public function getFormPredefinedContent(
        UserContentGenerationRequest $contentGenerationRequest
    ): array {
        return $this->productContextProvider->getFullContext($contentGenerationRequest, $this->pluralForm);
    }
}
