<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Controller;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\Controller\SummaryWidgetController;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryGenerator;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\CustomerStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Covers that the session is released before the AI is called, that a render error is logged with classes and
 * ids only and answered with the marked empty wrapper, the 404 refusals (a non-XHR request,
 * a record {@see SummaryAccessProvider} does not return), and that `refresh=1` (Regenerate) reaches the generator
 * only while the Regenerate button is enabled.
 */
final class SummaryWidgetControllerTest extends TestCase
{
    private const string CLASS_ALIAS = 'Acme_Customer';
    private const string EMPTY_WRAPPER = '<div class="widget-content" data-smart-brief-empty="1"></div>';

    private SummaryAccessProvider&MockObject $accessProvider;
    private DoctrineHelper&MockObject $doctrineHelper;
    private SummaryGenerator&MockObject $generator;
    private LoggerInterface&MockObject $logger;
    private Environment&MockObject $twig;
    private ConfigManager&MockObject $configManager;
    private CustomerStub $entity;
    private SummaryWidgetController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $this->accessProvider = $this->createMock(SummaryAccessProvider::class);
        $this->doctrineHelper = $this->createMock(DoctrineHelper::class);
        $this->generator = $this->createMock(SummaryGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->entity = new CustomerStub();

        $this->controller = new SummaryWidgetController(
            $this->accessProvider,
            $this->doctrineHelper,
            $this->generator,
            $this->logger,
            $this->configManager
        );
        $container = new Container();
        $container->set('twig', $this->twig);
        $this->controller->setContainer($container);
    }

    public function testSessionIsReleasedBeforeTheAiIsCalled(): void
    {
        $this->expectViewableEntity();
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('isStarted')->willReturn(true);
        $saved = false;
        $session->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function () use (&$saved): void {
                $saved = true;
            });
        $this->generator->expects(self::once())
            ->method('generate')
            ->with(self::identicalTo($this->entity))
            ->willReturnCallback(static function () use (&$saved): ?SummaryView {
                self::assertTrue($saved, 'The session must be saved before the AI is called.');

                return null;
            });
        $this->twig->expects(self::once())
            ->method('render')
            ->with('@OroSmartBrief/Widget/empty.html.twig', [])
            ->willReturn(self::EMPTY_WRAPPER);

        $response = $this->controller->widgetAction($this->createXhrRequest($session), self::CLASS_ALIAS, '42');

        self::assertSame(self::EMPTY_WRAPPER, $response->getContent());
    }

    public function testSummaryIsRenderedFromTheView(): void
    {
        $this->expectViewableEntity();
        $view = new SummaryView('Acme ordered twice.', new FactSet(), false, new \DateTimeImmutable('2026-10-07'));
        $this->generator->expects(self::once())->method('generate')->willReturn($view);
        $this->twig->expects(self::once())
            ->method('render')
            ->with('@OroSmartBrief/Widget/summary.html.twig', ['view' => $view, 'entity' => $this->entity])
            ->willReturn('<div class="widget-content smart-brief">…</div>');
        $this->logger->expects(self::never())->method('error');

        $response = $this->controller->widgetAction($this->createXhrRequest(), self::CLASS_ALIAS, '42');

        self::assertStringContainsString('smart-brief', $response->getContent());
    }

    /**
     * @dataProvider refreshDataProvider
     */
    public function testRefreshIsHonouredOnlyWhileRegenerateIsEnabled(
        ?string $refresh,
        bool $regenerateEnabled,
        bool $expectedRefresh
    ): void {
        $this->expectViewableEntity();
        $this->configManager->expects(self::any())
            ->method('get')
            ->with('oro_smart_brief.regenerate_enabled')
            ->willReturn($regenerateEnabled);
        $this->generator->expects(self::once())
            ->method('generate')
            ->with(self::identicalTo($this->entity), $expectedRefresh)
            ->willReturn(null);
        $this->twig->expects(self::once())->method('render')->willReturn(self::EMPTY_WRAPPER);
        $request = $this->createXhrRequest();
        if (null !== $refresh) {
            $request->query->set('refresh', $refresh);
        }

        $this->controller->widgetAction($request, self::CLASS_ALIAS, '42');
    }

    public static function refreshDataProvider(): array
    {
        return [
            'no refresh' => [null, true, false],
            'refresh while enabled' => ['1', true, true],
            'refresh while disabled' => ['1', false, false],
            'refresh=0' => ['0', true, false],
        ];
    }

    public function testRenderErrorIsLoggedWithClassesOnlyAndAnsweredWithTheEmptyWrapper(): void
    {
        $this->expectViewableEntity();
        $this->generator->expects(self::once())
            ->method('generate')
            ->willReturn(new SummaryView('Secret text', new FactSet(), false, new \DateTimeImmutable('2026-10-07')));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief widget could not be rendered.', [
                'entity_class' => CustomerStub::class,
                'entity_id' => '42',
                'exception_class' => RuntimeError::class,
                'cause_class' => \RuntimeException::class,
            ]);
        $this->twig->expects(self::exactly(2))
            ->method('render')
            ->willReturnCallback(static function (string $template): string {
                if ('@OroSmartBrief/Widget/summary.html.twig' === $template) {
                    throw new RuntimeError('rendering failed', -1, null, new \RuntimeException('value type "secret"'));
                }
                self::assertSame('@OroSmartBrief/Widget/empty.html.twig', $template);

                return self::EMPTY_WRAPPER;
            });

        $response = $this->controller->widgetAction($this->createXhrRequest(), self::CLASS_ALIAS, '42');

        self::assertSame(self::EMPTY_WRAPPER, $response->getContent());
    }

    public function testNonXhrRequestIsRefusedBeforeAnything(): void
    {
        $this->accessProvider->expects(self::never())->method('getViewableEntity');
        $this->generator->expects(self::never())->method('generate');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Not found');

        $this->controller->widgetAction(new Request(), self::CLASS_ALIAS, '42');
    }

    public function testRecordTheUserMayNotSeeIsRefused(): void
    {
        $this->accessProvider->expects(self::once())
            ->method('getViewableEntity')
            ->with(self::CLASS_ALIAS, '42')
            ->willReturn(null);
        $this->generator->expects(self::never())->method('generate');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Not found');

        $this->controller->widgetAction($this->createXhrRequest(), self::CLASS_ALIAS, '42');
    }

    private function expectViewableEntity(): void
    {
        $this->accessProvider->expects(self::once())
            ->method('getViewableEntity')
            ->with(self::CLASS_ALIAS, '42')
            ->willReturn($this->entity);
        $this->doctrineHelper->expects(self::any())->method('getEntityClass')->willReturn(CustomerStub::class);
    }

    private function createXhrRequest(?SessionInterface $session = null): Request
    {
        $request = new Request();
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        if (null !== $session) {
            $request->setSession($session);
        }

        return $request;
    }
}
