<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Doctrine\Common\Collections\ArrayCollection;
use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\PopulateLandingPageDescriptionOpenPromptTask;
use Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Stub\Page as PageStub;
use Oro\Bundle\CMSBundle\Entity\Page;
use Oro\Bundle\CMSBundle\Form\Type\PageType;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LandingPageDescriptionOpenPromptTaskTest extends TestCase
{
    private PopulateLandingPageDescriptionOpenPromptTask $landingPageTask;
    private TranslatorInterface&MockObject $translator;
    private EntityFormResolver&MockObject $entityFormResolver;
    private UserContentGenerationRequest $request;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->entityFormResolver = $this->createMock(EntityFormResolver::class);

        $this->landingPageTask = new PopulateLandingPageDescriptionOpenPromptTask(
            $this->translator,
            $this->entityFormResolver,
            'plural'
        );

        $this->request = new UserContentGenerationRequest(
            PageType::NAME,
            [],
            '[plural]',
            [
                'content' => 'user sent content'
            ],
        );
    }

    public function testKeyValid(): void
    {
        self::assertEquals('populate_landing_page_description_with_open_prompt', $this->landingPageTask->getKey());
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
            $this->landingPageTask->getContext($this->request)
        );
    }

    public function testThatTaskSupportsRequest(): void
    {
        $collection = new ArrayCollection([
            (new LocalizedFallbackValue())->setString('Meta keywords')
        ]);

        $page = new PageStub();
        $page->addTitle((new LocalizedFallbackValue())->setString('Page title'));
        $page->setMetaKeywords($collection);

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                PageType::class,
                new Page(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($page);

        $this->translator
            ->expects(self::exactly(2))
            ->method('trans')
            ->withConsecutive(
                ['oro_ai_content_generation.form.context.landing_page.page_title.label'],
                ['oro_ai_content_generation.form.context.landing_page.meta_keywords.label'],
            )
            ->willReturnOnConsecutiveCalls('Page title', 'Meta Keywords');

        self::assertTrue($this->landingPageTask->supports($this->request));
    }

    public function testThatTaskNotSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            '',
            [],
            '[plural]',
            []
        );

        self::assertFalse($this->landingPageTask->supports($request));
    }

    public function testThatTranslationKeyValid(): void
    {
        self::assertEquals(
            sprintf(
                'oro_ai_content_generation.form.field.task.choices.%s.generation_phrase',
                'populate_landing_page_description_with_open_prompt'
            ),
            $this->landingPageTask->getContentGenerationPhraseTranslationKey()
        );
    }

    public function testThatFormPredefinedValuesReturned(): void
    {
        $collection = new ArrayCollection([
            (new LocalizedFallbackValue())->setString('Meta keywords')
        ]);

        $page = new PageStub();
        $page->addTitle((new LocalizedFallbackValue())->setString('Page title'));
        $page->setMetaKeywords($collection);

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                PageType::class,
                new Page(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($page);

        $this->translator
            ->expects(self::exactly(2))
            ->method('trans')
            ->withConsecutive(
                ['oro_ai_content_generation.form.context.landing_page.page_title.label'],
                ['oro_ai_content_generation.form.context.landing_page.meta_keywords.label'],
            )
            ->willReturnOnConsecutiveCalls('Page title', 'Meta Keywords');

        $expected = [
            new ContextItem('Page title', (string)$page->getTitle()),
            new ContextItem('Meta Keywords', (string)$page->getMetaKeyword())
        ];

        self::assertEquals(
            $expected,
            $this->landingPageTask->getFormPredefinedContent($this->request)
        );
    }

    public function testThatFormPredefinedValuesEmptyWhenContextItemsNotValid(): void
    {
        $collection = new ArrayCollection([
            (new LocalizedFallbackValue())->setString('')
        ]);

        $page = new PageStub();
        $page->addTitle((new LocalizedFallbackValue())->setString(''));
        $page->setMetaKeywords($collection);

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->with(
                PageType::class,
                new Page(),
                $this->request->getSubmittedFormData()
            )
            ->willReturn($page);

        self::assertEquals(
            [],
            $this->landingPageTask->getFormPredefinedContent($this->request)
        );
    }
}
