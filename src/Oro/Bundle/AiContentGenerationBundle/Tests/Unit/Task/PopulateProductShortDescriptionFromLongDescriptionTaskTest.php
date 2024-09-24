<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\PopulateProductShortDescriptionFromLongDescriptionTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PopulateProductShortDescriptionFromLongDescriptionTaskTest extends TestCase
{
    private PopulateProductShortDescriptionFromLongDescriptionTask $task;

    private ProductTaskContextProvider&MockObject $taskContextProvider;

    private UserContentGenerationRequest $request;

    #[\Override]
    protected function setUp(): void
    {
        $this->taskContextProvider = $this->createMock(ProductTaskContextProvider::class);

        $this->task = new PopulateProductShortDescriptionFromLongDescriptionTask(
            $this->taskContextProvider,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[shortDescriptions]',
            [],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals(
            'populate_short_description_from_long_description',
            $this->task->getKey()
        );
    }

    public function testThatContextValid(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn(new ContextItem('description', 'Description Value'));

        self::assertEquals(
            [
                new ContextItem('description', 'Description Value'),
            ],
            $this->task->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn(new ContextItem('description', 'Description Value'));

        self::assertTrue($this->task->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->task->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'populate_short_description_from_long_description'
            ),
            $this->task->getContentGenerationPhraseTranslationKey()
        );
    }
}
