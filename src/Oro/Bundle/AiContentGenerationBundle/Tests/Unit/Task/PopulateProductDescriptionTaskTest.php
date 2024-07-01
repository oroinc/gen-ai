<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\PopulateProductDescriptionTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PopulateProductDescriptionTaskTest extends TestCase
{
    private PopulateProductDescriptionTask $populateProductDescriptionTask;

    private ProductTaskContextProvider&MockObject $taskContextProvider;

    private UserContentGenerationRequest $request;

    protected function setUp(): void
    {
        $this->taskContextProvider = $this->createMock(ProductTaskContextProvider::class);

        $this->populateProductDescriptionTask = new PopulateProductDescriptionTask(
            $this->taskContextProvider,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[plural]',
            [],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals(
            'populate_product_description',
            $this->populateProductDescriptionTask->getKey()
        );
    }

    public function testThatContextValid(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getFullContext')
            ->willReturn([new ContextItem('key', 'value')]);

        self::assertEquals(
            [
                new ContextItem('key', 'value'),
            ],
            $this->populateProductDescriptionTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getFullContext')
            ->willReturn([new ContextItem('key', 'value')]);

        self::assertTrue($this->populateProductDescriptionTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->populateProductDescriptionTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'populate_product_description'
            ),
            $this->populateProductDescriptionTask->getContentGenerationPhraseTranslationKey()
        );
    }
}
