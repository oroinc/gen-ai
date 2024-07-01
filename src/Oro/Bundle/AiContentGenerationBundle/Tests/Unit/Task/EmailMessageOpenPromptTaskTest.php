<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\GenerateEmailMessageOpenPromptTask;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailMessageOpenPromptTaskTest extends TestCase
{
    use EntityTrait;

    private GenerateEmailMessageOpenPromptTask $emailMessageTask;
    private TranslatorInterface&MockObject $translator;
    private UserContentGenerationRequest $request;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->emailMessageTask = new GenerateEmailMessageOpenPromptTask(
            $this->translator,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            'oro_email_email',
            ['subject' => 'text subject'],
            '[plural]',
            [
                'content' => 'user sent content'
            ],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals('generate_email_message_with_open_prompt', $this->emailMessageTask->getKey());
    }

    public function testThatContextValid(): void
    {
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('oro_ai_content_generation.form.context.global.features.label')
            ->willReturn('Features');

        self::assertEquals(
            [
                new ContextItem('Features', 'user sent content'),
            ],
            $this->emailMessageTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('oro_ai_content_generation.form.context.email.goal.label')
            ->willReturn('Goal');

        self::assertTrue($this->emailMessageTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->emailMessageTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'generate_email_message_with_open_prompt'
            ),
            $this->emailMessageTask->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testThatFormPredefinedValuesReturned(): void
    {
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('oro_ai_content_generation.form.context.email.goal.label')
            ->willReturn('Goal');

        $expected = [new ContextItem('Goal', 'text subject')];

        self::assertEquals(
            $expected,
            $this->emailMessageTask->getFormPredefinedContent($this->request)
        );
    }

    public function testThatFormPredefinedValuesEmptyWhenContextItemsNotValid(): void
    {
        $request = new UserContentGenerationRequest(
            'oro_email_email',
            ['subject' => ''],
            '[plural]',
            [
                'content' => 'user sent content'
            ],
        );

        self::assertEquals(
            [],
            $this->emailMessageTask->getFormPredefinedContent($request)
        );
    }
}
