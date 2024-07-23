<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Handler;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationRequestFactory;
use Oro\Bundle\AiContentGenerationBundle\Form\Handler\AiContentGenerationFormHandler;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiContentGenerationFormHandlerTest extends TestCase
{
    private TasksProvider&MockObject $tasksProvider;

    private ContentGenerationClientInterface&MockObject $generationClient;

    private ContentGenerationRequestFactory&MockObject $contentGenerationRequestFactory;

    private TranslatorInterface&MockObject $translator;

    private FormInterface&MockObject $form;

    private TaskInterface&MockObject $task;

    private ContentGenerationRequest $request;

    private AiContentGenerationFormHandler $handler;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->tasksProvider = $this->createMock(TasksProvider::class);
        $this->generationClient = $this->createMock(ContentGenerationClientInterface::class);
        $this->contentGenerationRequestFactory = $this->createMock(ContentGenerationRequestFactory::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->form = $this->createMock(FormInterface::class);
        $this->task = $this->createMock(TaskInterface::class);
        $this->request = new ContentGenerationRequest('phrase', [], 'normal');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new AiContentGenerationFormHandler(
            $this->tasksProvider,
            $this->generationClient,
            $this->contentGenerationRequestFactory,
            $this->translator
        );

        $this->handler->setLogger($this->logger);
    }

    public function testHandleSuccess(): void
    {
        $data = ['task' => 'sample_task'];

        $this->form
            ->expects(self::atLeastOnce())
            ->method('getData')
            ->willReturn($data);

        $this->tasksProvider
            ->expects(self::once())
            ->method('getTask')
            ->with('sample_task')
            ->willReturn($this->task);

        $this->contentGenerationRequestFactory
            ->expects(self::once())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->generationClient
            ->method('generateTextContent')
            ->willReturn('generated_content');

        $result = $this->handler->handle($this->form);

        self::assertEquals('generated_content', $result);
    }

    public function testHandleFailure(): void
    {
        $data = ['task' => 'sample_task'];
        $exception = new ContentGenerationClientException();

        $this->form
            ->expects(self::atLeastOnce())
            ->method('getData')
            ->willReturn($data);

        $this->tasksProvider
            ->expects(self::once())
            ->method('getTask')
            ->with('sample_task')
            ->willReturn($this->task);

        $this->contentGenerationRequestFactory
            ->expects(self::once())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->generationClient
            ->expects(self::once())
            ->method('generateTextContent')
            ->willThrowException($exception);

        $this->translator
            ->expects(self::atLeastOnce())
            ->method('trans')
            ->with('oro_ai_content_generation.form.error.label')
            ->willReturn('translated_error');

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('AI Content Generation Client error occurred', ['exception' => $exception]);

        $this->form
            ->expects(self::once())
            ->method('addError')
            ->with(self::isInstanceOf(FormError::class));

        $result = $this->handler->handle($this->form);

        self::assertNull($result);
    }
}
