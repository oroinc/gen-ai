<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\ManualPromptTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ManualPromptTaskTest extends TestCase
{
    private ManualPromptTask $task;

    private UserContentGenerationRequest $request;

    private TranslatorInterface&MockObject $translator;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->task = new ManualPromptTask($this->translator);

        $this->request = new UserContentGenerationRequest(
            '',
            [],
            '',
            ['content' => 'Product: Test clicker'],
        );
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            'oro_ai_content_generation.form.field.task.choices.manual_prompt.generation_phrase',
            $this->task->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals('manual_prompt', $this->task->getKey());
    }

    public function testThatTaskAlwaysApplied(): void
    {
        self::assertTrue($this->task->supports($this->request));
    }

    public function testThatContextReturned(): void
    {
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->willReturn('Features');

        self::assertEquals(
            [new ContextItem('Features', 'Product: Test clicker')],
            $this->task->getContext($this->request)
        );
    }
}
