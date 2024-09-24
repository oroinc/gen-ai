<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\ShortenTextTask;
use Oro\Bundle\EntityBundle\Helper\FieldHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ShortenTextTaskTest extends TestCase
{
    private ShortenTextTask $task;

    private FieldHelper&MockObject $fieldHelper;

    private UserContentGenerationRequest $request;

    private TranslatorInterface&MockObject $translator;

    #[\Override]
    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->fieldHelper = $this->createMock(FieldHelper::class);
        $this->task = new ShortenTextTask($this->fieldHelper, $this->translator);

        $request = new UserContentGenerationRequest(
            '',
            [],
            '',
            [],
        );

        $this->request = $request;
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.form.field.task.choices.shorten.generation_phrase',
            $this->task->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals('shorten', $this->task->getKey());
    }

    public function testThatTaskAppliedWhenFieldValueIsValid(): void
    {
        $this->fieldHelper
            ->expects(self::once())
            ->method('getObjectValue')
            ->willReturn('<div>text</div>');

        self::assertTrue($this->task->supports($this->request));
    }

    public function testThatTaskNotAppliedWhenFieldValueIsNotValid(): void
    {
        $this->fieldHelper
            ->expects(self::once())
            ->method('getObjectValue')
            ->willReturn('<div> </div>');

        self::assertFalse($this->task->supports($this->request));
    }

    public function testThatContextReturned(): void
    {
        $this->fieldHelper
            ->expects(self::once())
            ->method('getObjectValue')
            ->willReturn('<div> text </div>');

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->willReturn('Value');

        self::assertEquals(
            [new ContextItem('Value', 'text')],
            $this->task->getContext($this->request)
        );
    }
}
