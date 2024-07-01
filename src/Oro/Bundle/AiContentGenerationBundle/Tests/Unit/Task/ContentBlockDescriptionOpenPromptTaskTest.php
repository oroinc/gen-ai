<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\PopulateContentBlockDescriptionOpenPromptTask;
use Oro\Bundle\CMSBundle\Entity\ContentBlock;
use Oro\Bundle\CMSBundle\Form\Type\ContentBlockType;
use Oro\Bundle\CMSBundle\Tests\Unit\Entity\Stub\ContentBlock as ContentBlockStub;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContentBlockDescriptionOpenPromptTaskTest extends TestCase
{
    private PopulateContentBlockDescriptionOpenPromptTask $contentBlockTask;
    private TranslatorInterface&MockObject $translator;
    private EntityFormResolver&MockObject $entityFormResolver;
    private UserContentGenerationRequest $request;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->entityFormResolver = $this->createMock(EntityFormResolver::class);

        $this->contentBlockTask = new PopulateContentBlockDescriptionOpenPromptTask(
            $this->translator,
            $this->entityFormResolver,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            ContentBlockType::BLOCK_PREFIX,
            [],
            '[plural]',
            [
                'content' => 'user sent content'
            ],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals('populate_content_block_description_with_open_prompt', $this->contentBlockTask->getKey());
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
            $this->contentBlockTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $contentBlock = new ContentBlockStub();
        $contentBlock->addTitle((new LocalizedFallbackValue())->setString('Content Block title'));

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                ContentBlockType::class,
                new ContentBlock(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($contentBlock);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('oro_ai_content_generation.form.context.content_block.title.label')
            ->willReturn('Content Block title');

        self::assertTrue($this->contentBlockTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->contentBlockTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'populate_content_block_description_with_open_prompt'
            ),
            $this->contentBlockTask->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testThatFormPredefinedValuesReturned(): void
    {
        $contentBlock = new ContentBlockStub();
        $contentBlock->addTitle((new LocalizedFallbackValue())->setString('Content Block title'));

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                ContentBlockType::class,
                new ContentBlock(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($contentBlock);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('oro_ai_content_generation.form.context.content_block.title.label')
            ->willReturn('Content Block title');

        $expected = [
            new ContextItem('Content Block title', (string)$contentBlock->getTitle()),
        ];

        self::assertEquals(
            $expected,
            $this->contentBlockTask->getFormPredefinedContent($this->request)
        );
    }

    public function testThatFormPredefinedContentEmptyWhenTitleIsEmpty(): void
    {
        $contentBlock = new ContentBlockStub();
        $contentBlock->addTitle((new LocalizedFallbackValue())->setString(''));

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                ContentBlockType::class,
                new ContentBlock(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($contentBlock);

        self::assertEquals(
            [],
            $this->contentBlockTask->getFormPredefinedContent($this->request)
        );
    }
}
