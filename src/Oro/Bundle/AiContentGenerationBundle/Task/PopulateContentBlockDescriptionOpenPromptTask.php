<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\CMSBundle\Entity\ContentBlock;
use Oro\Bundle\CMSBundle\Form\Type\ContentBlockType;
use Oro\Bundle\FormBundle\Resolver\EntityFormResolverInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates description for content block using predefined context
 */
class PopulateContentBlockDescriptionOpenPromptTask implements OpenPromptTaskInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EntityFormResolverInterface $entityFormResolver,
        private string $supportedFieldName
    ) {
    }

    #[\Override]
    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if ($contentGenerationRequest->getSubmittedFormName() !== ContentBlockType::BLOCK_PREFIX) {
            return false;
        }

        if (!str_contains($contentGenerationRequest->getSubmittedFormField(), $this->supportedFieldName)) {
            return false;
        }

        return !empty($this->getFormPredefinedContent($contentGenerationRequest));
    }

    #[\Override]
    public function getContentGenerationPhraseTranslationKey(): string
    {
        return sprintf(
            'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
            $this->getKey()
        );
    }

    #[\Override]
    public function getKey(): string
    {
        return 'populate_content_block_description_with_open_prompt';
    }

    #[\Override]
    public function getContext(UserContentGenerationRequest $contentGenerationRequest): array
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

    #[\Override]
    public function getFormPredefinedContent(UserContentGenerationRequest $contentGenerationRequest): array
    {
        $contentBlock = $this->entityFormResolver->resolve(
            ContentBlockType::class,
            new ContentBlock(),
            $contentGenerationRequest->getSubmittedFormData()
        );

        $title = (string)$contentBlock->getTitle();

        if (!$title) {
            return [];
        }

        return [
            new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.content_block.title.label'),
                $title
            ),
        ];
    }
}
