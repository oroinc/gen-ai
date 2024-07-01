<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\ProductBundle\Form\Type\ProductType;

/**
 * Abstraction for all tasks on product page
 */
abstract class AbstractProductTask
{
    public function __construct(
        protected readonly ProductTaskContextProvider $productContextProvider,
        protected string $pluralForm
    ) {
    }

    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if ($contentGenerationRequest->getSubmittedFormName() !== ProductType::NAME) {
            return false;
        }

        return str_contains(
            $contentGenerationRequest->getSubmittedFormField(),
            sprintf('[%s]', $this->pluralForm)
        );
    }

    abstract public function getKey(): string;

    public function getContentGenerationPhraseTranslationKey(): string
    {
        return sprintf(
            'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
            $this->getKey()
        );
    }
}
