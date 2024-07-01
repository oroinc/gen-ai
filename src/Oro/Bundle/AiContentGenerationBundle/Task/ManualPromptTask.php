<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Task allow user to input his own prompt and use it for the provided text
 */
class ManualPromptTask implements OpenPromptTaskInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator
    ) {
    }

    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        return true;
    }

    public function getFormPredefinedContent(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return [];
    }

    public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return [
            new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.global.features.label'),
                $contentGenerationRequest->getSubmittedContentGenerationFormData()['content']
            )
        ];
    }

    #[\Override] public function getContentGenerationPhraseTranslationKey(): string
    {
        return 'oro_ai_content_generation.form.field.task.choices.manual_prompt.generation_phrase';
    }

    #[\Override] public function getKey(): string
    {
        return 'manual_prompt';
    }
}
