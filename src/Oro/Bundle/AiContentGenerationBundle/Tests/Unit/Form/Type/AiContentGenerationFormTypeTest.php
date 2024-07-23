<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Type;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationRequestFactory;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\AiContentGenerationFormType;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\AiContentGenerationBundle\Task\OpenPromptTaskInterface;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\FormBundle\Form\DataTransformer\ArrayToJsonTransformer;
use Oro\Bundle\FormBundle\Form\Extension\TooltipFormExtension;
use Oro\Bundle\TranslationBundle\Translation\Translator;
use Oro\Component\Testing\Unit\EntityTrait;
use Oro\Component\Testing\Unit\FormIntegrationTestCase;
use Oro\Component\Testing\Unit\PreloadedExtension;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiContentGenerationFormTypeTest extends FormIntegrationTestCase
{
    use EntityTrait;

    private ContentGenerationClientInterface&MockObject $contentGenerationClient;

    private TasksProvider&MockObject $tasksProvider;

    private ContentGenerationRequestFactory&MockObject $requestFactory;

    private LoggerInterface&MockObject $logger;

    private TranslatorInterface&MockObject $translator;

    private AiContentGenerationFormType $formType;

    protected function setUp(): void
    {
        $this->contentGenerationClient = $this->createMock(ContentGenerationClientInterface::class);
        $this->requestFactory = $this->createMock(ContentGenerationRequestFactory::class);
        $this->tasksProvider = $this->createMock(TasksProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->formType = new AiContentGenerationFormType(
            $this->contentGenerationClient,
            $this->tasksProvider,
            $this->requestFactory,
            $this->translator,
            []
        );
        $this->formType->setLogger($this->logger);

        parent::setUp();
    }

    public function testThatFormHasElementsOnRender(): void
    {
        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'

            ]
        );

        $submittedFormData = $form->get('source_form_submitted_form_data');

        self::assertFormContainsField('tone', $form);
        self::assertFormContainsField('source_form_submitted_form_name', $form);
        self::assertFormContainsField('source_form_submitted_form_data', $form);
        self::assertFormContainsField('source_form_submitted_form_field', $form);
        self::assertFormContainsField('task', $form);
        self::assertFormContainsField('content', $form);
        self::assertInstanceOf(
            ArrayToJsonTransformer::class,
            $submittedFormData->getConfig()->getViewTransformers()[0]
        );
    }

    public function testThatFormContainsErrorWhenClientNotValid(): void
    {
        $exception = new ContentGenerationClientException('Not Valid');

        $this->contentGenerationClient
            ->expects(self::once())
            ->method('supportsUserContentSize')
            ->willThrowException($exception);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('AI Content Generation Client error occurred', ['exception' => $exception]);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->willReturn('Form error label');

        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'
            ]
        );

        self::assertEquals(
            'Form error label',
            $form->getErrors()->current()->getMessage()
        );
    }

    public function testThatContentSizeFieldAddedWhenClientSupports(): void
    {
        $this->contentGenerationClient
            ->expects(self::once())
            ->method('supportsUserContentSize')
            ->willReturn(true);

        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'
            ]
        );

        self::assertFormContainsField('content_size', $form);
    }

    public function testThatFirstTaskSelectedOnRender(): void
    {
        $task = $this->createMock(TaskInterface::class);

        $task
            ->expects(self::any())
            ->method('getKey')
            ->willReturn('key');

        $this->tasksProvider
            ->expects(self::once())
            ->method('getTasks')
            ->willReturn(new \ArrayIterator([$task]));

        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'
            ]
        );

        $data = $form->getData();

        self::assertEquals('key', $data['task']);
    }

    public function testThatPredefinedContentAdded(): void
    {
        $task = $this->createMock(OpenPromptTaskInterface::class);

        $task
            ->expects(self::any())
            ->method('getKey')
            ->willReturn('key');

        $this->tasksProvider
            ->expects(self::once())
            ->method('getTaskFormPredefinedContent')
            ->willReturn('predefined content');

        $this->tasksProvider
            ->expects(self::once())
            ->method('getOpenPromptTaskKeys')
            ->willReturn(['key']);

        $this->tasksProvider
            ->expects(self::once())
            ->method('getTasks')
            ->willReturn(new \ArrayIterator([$task]));

        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'
            ]
        );

        $data = $form->getData();

        self::assertEquals('predefined content', $data['content']);
    }

    public function testBlockPrefix(): void
    {
        self::assertEquals('oro_ai_content_generation', $this->formType->getBlockPrefix());
    }

    public function testThatFormHasPreview(): void
    {
        $form = $this->factory->create(
            $this->formType::class,
            [
                'source_form_submitted_form_data' => [],
                'source_form_submitted_form_name' => 'form_name',
                'source_form_submitted_form_field' => 'field_name'
            ]
        );

        $form->submit([
            'task' => 'key',
            'tone' => 'User selected tone',
            'content_size' => 'size'
        ]);

        self::assertFormContainsField('preview', $form);
    }

    protected function getExtensions(): array
    {
        $translator = $this->createMock(Translator::class);

        return [
            new PreloadedExtension(
                [
                    $this->formType,
                ],
                [
                    FormType::class => [
                        new TooltipFormExtension($this->createMock(ConfigProvider::class), $translator),
                    ],
                ]
            ),
            new ValidatorExtension(Validation::createValidator()),
        ];
    }
}
