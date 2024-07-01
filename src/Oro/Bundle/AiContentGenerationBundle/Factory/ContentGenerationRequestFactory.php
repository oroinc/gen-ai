<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\AiContentGenerationFormType;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds ContentGenerationRequest
 */
class ContentGenerationRequestFactory
{
    private const CHARACTERS_IN_TOKEN = 4;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly ContentGenerationClientInterface $contentGenerationClient,
        private array $charactersAmounts
    ) {
    }

    public function getRequest(TaskInterface $task, array $parameters): ContentGenerationRequest
    {
        $context = $this->getContext($task);

        if (!$context) {
            throw new ContentGenerationClientException('Task context should be provided for generation');
        }

        $request = new ContentGenerationRequest(
            $this->translator->trans($task->getContentGenerationPhraseTranslationKey()),
            $context,
            $this->translator->trans(
                sprintf('oro_ai_content_generation.form.field.tone.choices.%s.label', $parameters['tone'])
            )
        );

        $maxTokens = $this->getMaxTokens($parameters);

        if (!is_null($maxTokens)) {
            $request->setMaxTokens($maxTokens);
        }

        return $request;
    }

    private function getMaxTokens(array $parameters): ?int
    {
        if (!$this->contentGenerationClient->supportsUserContentSize()) {
            return null;
        }

        if (!isset($this->charactersAmounts[$parameters['content_size']])) {
            throw new ContentGenerationClientException(
                sprintf('Content size %s is not supported', $parameters['content_size'])
            );
        }

        $charactersAmount = $this->charactersAmounts[$parameters['content_size']];

        return $charactersAmount / self::CHARACTERS_IN_TOKEN;
    }

    private function getContext(TaskInterface $task): array
    {
        $requestData = $this->requestStack->getCurrentRequest()?->request?->all();
        $userContentGenerationRequest = UserContentGenerationRequest::fromSubmitRequest(
            $requestData[AiContentGenerationFormType::BLOCK_PREFIX] ?? []
        );

        $contextLines = [];

        foreach ($task->getContext($userContentGenerationRequest) as $contextItem) {
            $contextLines[] = $contextItem->getTextRepresentation();
        }

        return $contextLines;
    }
}
