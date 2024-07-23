<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationRequestFactory;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContentGenerationRequestFactoryTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private ContentGenerationClientInterface&MockObject $contentGenerationClient;

    private array $charactersAmounts;

    private ContentGenerationRequestFactory $factory;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->contentGenerationClient = $this->createMock(ContentGenerationClientInterface::class);
        $this->charactersAmounts = ['short' => 100, 'medium' => 200, 'long' => 400];
        $this->request = $this->createMock(Request::class);

        $this->factory = new ContentGenerationRequestFactory(
            $this->translator,
            $this->contentGenerationClient,
            $this->charactersAmounts
        );
    }

    public function testThatErrorThrownWhenContextEmpty(): void
    {
        $parameters = ['tone' => 'formal'];
        $task = $this->createMock(TaskInterface::class);
        $task
            ->expects(self::once())
            ->method('getContext')
            ->willReturn([]);

        self::expectException(ContentGenerationClientException::class);

        $this->factory->getRequest($task, $parameters);
    }

    public function testThatRequestReturned(): void
    {
        $task = $this->createMock(TaskInterface::class);
        $parameters = [
            'tone' => 'formal',
            'source_form_submitted_form_name' => 'form_name',
            'source_form_submitted_form_data' => [],
            'source_form_submitted_form_field' => 'form_field',
        ];

        $task
            ->expects(self::once())
            ->method('getContentGenerationPhraseTranslationKey')
            ->willReturn('translation_key');

        $this->translator
            ->expects(self::any())
            ->method('trans')
            ->willReturnMap([
                ['translation_key', [], null, null, 'translated_phrase'],
                ['oro_ai_content_generation.form.field.tone.choices.formal.label', [], null, null, 'formal_tone']
        ]);

        $task
            ->expects(self::once())
            ->method('getContext')
            ->willReturn([new ContextItem('key', 'value')]);

        $request = $this->factory->getRequest($task, $parameters);

        self::assertInstanceOf(ContentGenerationRequest::class, $request);
        self::assertEquals('translated_phrase', $request->getTaskGenerationPhrase());
        self::assertEquals('formal_tone', $request->getTone());
        self::assertEquals(['key value'], $request->getContext());
    }

    public function testThatRequestHasMaxTokens(): void
    {
        $this->contentGenerationClient
            ->expects(self::once())
            ->method('supportsUserContentSize')
            ->willReturn(true);

        $task = $this->createMock(TaskInterface::class);

        $task
            ->expects(self::once())
            ->method('getContext')
            ->willReturn([new ContextItem('key', 'value')]);

        $parameters = [
            'tone' => 'formal',
            'content_size' => 'short',
            'source_form_submitted_form_name' => 'form_name',
            'source_form_submitted_form_data' => [],
            'source_form_submitted_form_field' => 'form_field',
        ];

        $task
            ->expects(self::once())
            ->method('getContentGenerationPhraseTranslationKey')
            ->willReturn('translation_key');

        $this->translator
            ->expects(self::any())
            ->method('trans')
            ->willReturnMap([
                ['translation_key', [], null, null, 'translated_phrase'],
                ['oro_ai_content_generation.form.field.tone.choices.formal.label', [], null, null, 'formal_tone']
            ]);

        $request = $this->factory->getRequest($task, $parameters);

        self::assertEquals(25, $request->getMaxTokens());
    }

    public function testGetMaxTokensUnsupportedContentSize(): void
    {
        $this->contentGenerationClient
            ->expects(self::once())
            ->method('supportsUserContentSize')
            ->willReturn(true);

        $task = $this->createMock(TaskInterface::class);

        $task
            ->expects(self::once())
            ->method('getContext')
            ->willReturn([new ContextItem('key', 'value')]);

        $parameters = [
            'tone' => 'formal',
            'content_size' => 'unsupported',
            'source_form_submitted_form_name' => 'form_name',
            'source_form_submitted_form_data' => [],
            'source_form_submitted_form_field' => 'form_field',
        ];

        $task
            ->expects(self::once())
            ->method('getContentGenerationPhraseTranslationKey')
            ->willReturn('translation_key');

        $this->translator
            ->expects(self::any())
            ->method('trans')
            ->willReturnMap([
                ['translation_key', [], null, null, 'translated_phrase'],
                ['oro_ai_content_generation.form.field.tone.choices.formal.label', [], null, null, 'formal_tone']
            ]);

        $task
            ->expects(self::once())
            ->method('getContext')
            ->willReturn([]);

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('Content size unsupported is not supported');

        $this->factory->getRequest($task, $parameters);
    }
}
