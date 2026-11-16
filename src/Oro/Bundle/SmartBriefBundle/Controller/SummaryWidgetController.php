<?php

namespace Oro\Bundle\SmartBriefBundle\Controller;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryGenerator;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves SmartBrief content after the record page has loaded.
 *
 * - Only in-page (XHR) requests are answered; a record {@see SummaryAccessProvider::getViewableEntity()} does not
 *   return (an unknown or unsupported class, a malformed id, a missing record, a record the user may not view)
 *   gets the same 404, so the route reveals nothing about records the user may not see.
 * - The session is released before the AI is called, so the user's other requests are not blocked.
 * - A hidden block is a marked empty widget wrapper, never an empty body (the block widget needs
 *   `.widget-content`); a template error is logged with its class only and answered the same way.
 * - `refresh=1` (the Regenerate button) writes the summary again, bypassing the cache; it is honoured only while
 *   the Regenerate button is enabled in the configuration, so the AI cannot be called on demand otherwise.
 * - A supported but switched-off type reaches the generator, which hides the block (200).
 */
class SummaryWidgetController extends AbstractController
{
    private const string NOT_FOUND_MESSAGE = 'Not found';

    public function __construct(
        private readonly SummaryAccessProvider $accessProvider,
        private readonly DoctrineHelper $doctrineHelper,
        private readonly SummaryGenerator $generator,
        private readonly LoggerInterface $logger,
        private readonly ConfigManager $configManager
    ) {
    }

    #[Route(path: '/widget/{entityClass}/{entityId}', name: 'oro_smart_brief_widget', methods: ['GET'])]
    public function widgetAction(Request $request, string $entityClass, string $entityId): Response
    {
        $entity = $request->isXmlHttpRequest()
            ? $this->accessProvider->getViewableEntity($entityClass, $entityId)
            : null;
        if (null === $entity) {
            throw $this->createNotFoundException(self::NOT_FOUND_MESSAGE);
        }

        // Release the session before the (slow) generation so the user's other requests are not blocked.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $view = $this->generator->generate($entity, $this->isRefreshRequested($request));
        if (null === $view) {
            return $this->renderEmpty();
        }

        try {
            return $this->render('@OroSmartBrief/Widget/summary.html.twig', ['view' => $view, 'entity' => $entity]);
        } catch (\Throwable $exception) {
            // Twig wraps template-time failures; the innermost class names the real cause (classes only).
            $this->logger->error('SmartBrief widget could not be rendered.', [
                'entity_class' => $this->doctrineHelper->getEntityClass($entity),
                'entity_id' => $entityId,
                'exception_class' => $exception::class,
                'cause_class' => $this->getRootCause($exception)::class,
            ]);

            return $this->renderEmpty();
        }
    }

    private function isRefreshRequested(Request $request): bool
    {
        return $request->query->getBoolean('refresh')
            && (bool)$this->configManager->get(
                Configuration::getConfigKeyByName(Configuration::REGENERATE_ENABLED_KEY)
            );
    }

    private function getRootCause(\Throwable $exception): \Throwable
    {
        while (null !== $exception->getPrevious()) {
            $exception = $exception->getPrevious();
        }

        return $exception;
    }

    private function renderEmpty(): Response
    {
        return $this->render('@OroSmartBrief/Widget/empty.html.twig');
    }
}
