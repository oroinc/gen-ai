<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Prompt;

use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Prompt\SummaryRequestBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the request builder: prompts, the facts block, the language and the sampling parameters.
 *
 * The language is the user's (`LocaleSettings::getLanguage()`); Oro's locale listener sets the translator to it for
 * every back-office request, which the mocks below mirror. The English fallback for a missing translation is
 * the platform translator's (its locale fallback chain ends in `en`).
 */
final class SummaryRequestBuilderTest extends TestCase
{
    private const array TRANSLATIONS = [
        'en' => [
            'oro_smart_brief.prompt.common' => 'Use only the facts. Reply in %language%.',
            'oro_smart_brief.prompt.customer' => 'Summarize the customer.',
        ],
        'de' => [
            'oro_smart_brief.prompt.common' => 'Nur Fakten. Antworte auf %language%.',
            // no German customer prompt: the translator falls back to English
        ],
    ];

    private TranslatorInterface&MockObject $translator;
    private FactFormatter&MockObject $factFormatter;
    private SummaryRequestBuilder $builder;
    /** The user's language: what the locale settings return and the translator is set to */
    private string $language = 'en';

    #[\Override]
    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->expects(self::any())
            ->method('trans')
            ->willReturnCallback(function (string $id, array $parameters = []) {
                $language = substr($this->language, 0, 2);
                $text = self::TRANSLATIONS[$language][$id] ?? self::TRANSLATIONS['en'][$id] ?? $id;

                return strtr($text, $parameters);
            });
        $this->factFormatter = $this->createMock(FactFormatter::class);
        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->expects(self::any())->method('getLanguage')->willReturnCallback(fn () => $this->language);
        $this->builder = new SummaryRequestBuilder(
            $this->translator,
            $this->factFormatter,
            $localeSettings,
            0.2,
            1024
        );
    }

    public function testEnglishRequest(): void
    {
        $facts = new FactSet();
        $this->language = 'en';
        $this->expectFactsBlock($facts, "<facts>\nCompany: Acme\n</facts>");

        $request = $this->builder->build('customer', $facts);

        self::assertSame(
            "Use only the facts. Reply in English.\n\nSummarize the customer.",
            $request->systemPrompt
        );
        self::assertSame("<facts>\nCompany: Acme\n</facts>", $request->userContent);
        self::assertSame('en', $request->languageCode);
        self::assertSame(0.2, $request->temperature);
        self::assertSame(1024, $request->maxOutputTokens);
    }

    /**
     * @dataProvider germanCodeDataProvider
     */
    public function testGermanRequestWithEnglishFallbackForAMissingPromptPart(string $languageCode): void
    {
        $facts = new FactSet();
        $this->language = $languageCode;
        $this->expectFactsBlock($facts, "<facts>\n</facts>");

        $request = $this->builder->build('customer', $facts);

        self::assertSame(
            "Nur Fakten. Antworte auf German.\n\nSummarize the customer.",
            $request->systemPrompt
        );
        self::assertSame($languageCode, $request->languageCode);
    }

    public static function germanCodeDataProvider(): array
    {
        return ['language' => ['de'], 'region-qualified as Oro stores it' => ['de_DE']];
    }

    public function testRegionQualifiedEnglishIsNamedEnglish(): void
    {
        $facts = new FactSet();
        $this->language = 'en_US';
        $this->expectFactsBlock($facts, "<facts>\n</facts>");

        $request = $this->builder->build('customer', $facts);

        self::assertStringContainsString('Reply in English.', $request->systemPrompt);
    }

    public function testUnknownLanguageCodeIsUsedAsIs(): void
    {
        $facts = new FactSet();
        $this->language = 'xx';
        $this->expectFactsBlock($facts, "<facts>\n</facts>");

        $request = $this->builder->build('rfq', $facts);

        self::assertStringContainsString('Reply in xx.', $request->systemPrompt);
        self::assertStringContainsString('oro_smart_brief.prompt.rfq', $request->systemPrompt);
    }

    private function expectFactsBlock(FactSet $facts, string $block): void
    {
        $this->factFormatter->expects(self::once())
            ->method('formatFactsBlock')
            ->with(self::identicalTo($facts))
            ->willReturn($block);
    }
}
