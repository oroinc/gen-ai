<?php

namespace Oro\Bundle\AiContentGenerationBundle\Controller;

use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Form\Handler\AiContentGenerationFormHandler;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\AiContentGenerationFormType;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\IntegrationBundle\Form\Type\ChannelType;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AI Content Generation Controller
 */
class AiContentGenerationController extends AbstractController
{
    #[Route(
        path: '/validate-connection/{channelId}/',
        name: 'oro_ai_content_generation_validate_connection',
        methods: ['POST']
    )]
    #[ParamConverter('channel', class: Channel::class, options: ['id' => 'channelId'])]
    #[CsrfProtection()]
    public function validateConnectionAction(Request $request, ?Channel $channel = null): JsonResponse
    {
        try {
            $channel = $channel ?? new Channel();
            $form = $this->createForm(ChannelType::class, $channel);

            $form->handleRequest($request);

            $this->getFactory()->getClient($channel)->checkConnection();

            return new JsonResponse([
                'success' => true,
                'message' => $this->getTranslator()->trans(
                    'oro_ai_content_generation.integration.check_connection.result.success.message'
                ),
            ]);
        } catch (ContentGenerationClientException $exception) {
            $this->getLogger()->warning(
                'AI Content Generation Client connection error occurred',
                ['exception' => $exception]
            );

            return new JsonResponse([
                'success' => false,
                'message' => $this->getTranslator()->trans(
                    'oro_ai_content_generation.integration.check_connection.result.error.message'
                )
            ]);
        }
    }

    #[Route(path: '/widget', name: 'oro_ai_content_generation_form', methods: ['POST'])]
    #[Template('@OroAiContentGeneration/Form/update.html.twig')]
    public function form(Request $request): array
    {
        $requestData = $request->request->all();

        $form = $this->createForm(
            AiContentGenerationFormType::class,
            [
                'source_form_submitted_form_name' => $requestData['submitted_form_name'] ?? '',
                'source_form_submitted_form_data' => $requestData['submitted_form_data'] ?? [],
                'source_form_submitted_form_field' => $requestData['submitted_form_field'] ?? ''
            ]
        );

        return [
            'saved' => false,
            'form' => $form->createView(),
            'form_route' => 'oro_ai_content_generation_update'
        ];
    }

    #[Route(path: '/update-widget', name: 'oro_ai_content_generation_update', methods: ['POST'])]
    #[Template('@OroAiContentGeneration/Form/update.html.twig')]
    public function update(Request $request): array
    {
        $requestData = $request->request->all();

        $form = $this->createForm(
            AiContentGenerationFormType::class,
            $requestData[AiContentGenerationFormType::BLOCK_PREFIX] ?? []
        );

        $form->handleRequest($request);

        if ($form->isValid()) {
            $generatedText = $this->getFormHandler()->handle($form);
        }

        return [
            'saved' => $form->isSubmitted() && $form->isValid(),
            'form' => $form->createView(),
            'generatedText' => $generatedText ?? null,
            'form_route' => 'oro_ai_content_generation_update'
        ];
    }

    #[Route(path: '/widget-content-value', name: 'oro_ai_content_generation_form_content', methods: ['POST'])]
    public function getFormPredefinedContent(Request $request): JsonResponse
    {
        $taskKey = $request->get('task');

        $content = $this->getTasksProvider()->getTaskFormPredefinedContent(
            $taskKey,
            UserContentGenerationRequest::fromRenderRequest($request->request->all())
        );

        return new JsonResponse(compact('content'));
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'oro_ai_content_generation.provider.tasks_provider' => '?'.TasksProvider::class,
            'oro_ai_content_generation.factory.ai_client_factory' => '?'.ContentGenerationClientFactory::class,
            'oro_ai_content_generation.form.handler' => '?'.AiContentGenerationFormHandler::class,
            TranslatorInterface::class,
            LoggerInterface::class,
        ]);
    }

    private function getFactory(): ContentGenerationClientFactory
    {
        return $this->container->get('oro_ai_content_generation.factory.ai_client_factory');
    }

    private function getTasksProvider(): TasksProvider
    {
        return $this->container->get('oro_ai_content_generation.provider.tasks_provider');
    }

    private function getFormHandler(): AiContentGenerationFormHandler
    {
        return $this->container->get('oro_ai_content_generation.form.handler');
    }

    private function getTranslator(): TranslatorInterface
    {
        return $this->container->get(TranslatorInterface::class);
    }

    private function getLogger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }
}
