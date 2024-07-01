<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates email message using predefined context
 */
class GenerateEmailMessageOpenPromptTask implements OpenPromptTaskInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private string $supportedFieldName
    ) {
    }

    #[\Override] public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if ($contentGenerationRequest->getSubmittedFormName() !== 'oro_email_email') {
            return false;
        }

        if (!str_contains($contentGenerationRequest->getSubmittedFormField(), $this->supportedFieldName)) {
            return false;
        }

        return !empty($this->getFormPredefinedContent($contentGenerationRequest));
    }

    #[\Override] public function getContentGenerationPhraseTranslationKey(): string
    {
        return sprintf(
            'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
            $this->getKey()
        );
    }

    #[\Override] public function getKey(): string
    {
        return 'generate_email_message_with_open_prompt';
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

    #[\Override] public function getFormPredefinedContent(UserContentGenerationRequest $contentGenerationRequest): array
    {
        $formData = $contentGenerationRequest->getSubmittedFormData();

        if (!isset($formData['subject']) || !$formData['subject']) {
            return [];
        }

        return [
            new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.email.goal.label'),
                (string)$formData['subject']
            ),
        ];
    }
}
