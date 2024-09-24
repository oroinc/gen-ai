<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\PopulateProductDescriptionOpenPromptTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PopulateProductDescriptionOpenPromptTaskTest extends TestCase
{
    private PopulateProductDescriptionOpenPromptTask $populateProductDescriptionOpenPromptTask;

    private ProductTaskContextProvider&MockObject $taskContextProvider;

    private TranslatorInterface&MockObject $translator;

    private UserContentGenerationRequest $request;

    #[\Override]
    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->taskContextProvider = $this->createMock(ProductTaskContextProvider::class);

        $this->populateProductDescriptionOpenPromptTask = new PopulateProductDescriptionOpenPromptTask(
            $this->translator,
            $this->taskContextProvider,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[plural]',
            [
                'content' => 'user sent content'
            ],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals(
            'populate_product_description_with_open_prompt',
            $this->populateProductDescriptionOpenPromptTask->getKey()
        );
    }

    public function testThatContextValid(): void
    {
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->willReturn('Features');

        self::assertEquals(
            [
                new ContextItem('Features', 'user sent content'),
            ],
            $this->populateProductDescriptionOpenPromptTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getFullContext')
            ->willReturn([new ContextItem('key', 'value')]);

        self::assertTrue($this->populateProductDescriptionOpenPromptTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->populateProductDescriptionOpenPromptTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'populate_product_description_with_open_prompt'
            ),
            $this->populateProductDescriptionOpenPromptTask->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testThatFormPredefinedValuesReturned(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getFullContext')
            ->willReturn([]);

        self::assertEquals(
            [],
            $this->populateProductDescriptionOpenPromptTask->getFormPredefinedContent($this->request)
        );
    }
}
