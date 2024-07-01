<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\EntityBundle\Helper\FieldHelper;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Represents task that requires and works only with one field where the form was called
 */
abstract class AbstractSimpleTask
{
    public function __construct(
        private readonly FieldHelper $fieldHelper,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        return (bool)$this->getFieldValue($contentGenerationRequest);
    }

    public function getContentGenerationPhraseTranslationKey(): string
    {
        return sprintf(
            'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
            $this->getKey()
        );
    }

    public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
    {
        return [
            new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.global.value.label'),
                $this->getFieldValue($contentGenerationRequest)
            )
        ];
    }

    abstract public function getKey(): string;

    private function getFieldValue(UserContentGenerationRequest $contentGenerationRequest): string
    {
        $value = $this->fieldHelper->getObjectValue(
            $contentGenerationRequest->getSubmittedFormData(),
            str_replace(
                $contentGenerationRequest->getSubmittedFormName(),
                '',
                $contentGenerationRequest->getSubmittedFormField()
            )
        );

        return trim(strip_tags($value));
    }
}
