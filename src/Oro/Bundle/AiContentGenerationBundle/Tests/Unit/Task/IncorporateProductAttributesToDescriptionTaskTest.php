<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\IncorporateProductAttributesToDescriptionTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IncorporateProductAttributesToDescriptionTaskTest extends TestCase
{
    private IncorporateProductAttributesToDescriptionTask $incorporateProductAttributesToDescriptionTask;

    private ProductTaskContextProvider&MockObject $taskContextProvider;

    private UserContentGenerationRequest $request;

    #[\Override]
    protected function setUp(): void
    {
        $this->taskContextProvider = $this->createMock(ProductTaskContextProvider::class);

        $this->incorporateProductAttributesToDescriptionTask = new IncorporateProductAttributesToDescriptionTask(
            $this->taskContextProvider,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[plural]',
            []
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals(
            'incorporate_product_attributes_to_description',
            $this->incorporateProductAttributesToDescriptionTask->getKey()
        );
    }

    public function testThatContextValid(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn(new ContextItem('key', 'value'));

        $this->taskContextProvider
            ->expects(self::once())
            ->method('getAttributes')
            ->willReturn([new ContextItem('attr', 'attr_value')]);

        self::assertEquals(
            [
                new ContextItem('key', 'value'),
                new ContextItem('attr', 'attr_value'),
            ],
            $this->incorporateProductAttributesToDescriptionTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $this->taskContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn(new ContextItem('key', 'value'));

        $this->taskContextProvider
            ->expects(self::once())
            ->method('getAttributes')
            ->willReturn([new ContextItem('attr', 'attr_value')]);

        self::assertTrue($this->incorporateProductAttributesToDescriptionTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->incorporateProductAttributesToDescriptionTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'incorporate_product_attributes_to_description'
            ),
            $this->incorporateProductAttributesToDescriptionTask->getContentGenerationPhraseTranslationKey()
        );
    }
}
