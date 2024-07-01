<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\CMSBundle\Entity\Page;
use Oro\Bundle\CMSBundle\Form\Type\PageType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Generates description for the landing page using predefined context
 */
class PopulateLandingPageDescriptionOpenPromptTask implements OpenPromptTaskInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly EntityFormResolver $entityFormResolver,
        private string $supportedFieldName
    ) {
    }

    #[\Override] public function supports(UserContentGenerationRequest $contentGenerationRequest): bool
    {
        if ($contentGenerationRequest->getSubmittedFormName() !== PageType::NAME) {
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
        return 'populate_landing_page_description_with_open_prompt';
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
        $contextItems = [];

        /**
         * @var Page $page
         */
        $page = $this->entityFormResolver->resolve(
            PageType::class,
            new Page(),
            $contentGenerationRequest->getSubmittedFormData()
        );

        $title = (string)$page->getTitle();
        $metaKeyword = (string)$page->getMetaKeyword();

        if ($title) {
            $contextItems[] = new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.landing_page.page_title.label'),
                $title
            );
        }

        if ($metaKeyword) {
            $contextItems[] = new ContextItem(
                $this->translator->trans('oro_ai_content_generation.form.context.landing_page.meta_keywords.label'),
                $metaKeyword
            );
        }

        return $contextItems;
    }
}
