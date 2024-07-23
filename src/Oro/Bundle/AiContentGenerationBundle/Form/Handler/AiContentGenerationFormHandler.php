<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Handler;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationRequestFactory;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Handles AiContentGenerationForm form processing and return generatedText
 */
class AiContentGenerationFormHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly TasksProvider $tasksProvider,
        private readonly ContentGenerationClientInterface $generationClient,
        private readonly ContentGenerationRequestFactory $contentGenerationRequestFactory,
        private readonly TranslatorInterface $translator
    ) {
        $this->logger = new NullLogger();
    }

    public function handle(FormInterface $form): ?string
    {
        $data = $form->getData();

        $task = $this->tasksProvider->getTask($data['task']);

        try {
            return $this->generationClient->generateTextContent(
                $this->contentGenerationRequestFactory->getRequest($task, $data)
            );
        } catch (ContentGenerationClientException $exception) {
            $this->logger->error(
                'AI Content Generation Client error occurred',
                ['exception' => $exception]
            );

            $form->addError(
                new FormError($this->translator->trans('oro_ai_content_generation.form.error.label'))
            );
        }

        return null;
    }
}
