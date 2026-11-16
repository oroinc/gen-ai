<?php

namespace Oro\Bundle\SmartBriefBundle\Prompt;

use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the AI request for one record: the translated instructions, the facts block and
 * the client parameters, in the current user's back-office language: the language of the
 * user's localization (`oro_locale.default_localization`, user → organization → system), which Oro's translator
 * also uses for the request. Nothing outside the FactSet reaches the request.
 */
class SummaryRequestBuilder
{
    private const string PROMPT_COMMON_KEY = 'oro_smart_brief.prompt.common';
    private const string PROMPT_ENTITY_KEY_PATTERN = 'oro_smart_brief.prompt.%s';
    private const string LANGUAGE_PARAMETER = '%language%';
    private const string FACTS_OPEN_PARAMETER = '%facts_open%';
    private const string FACTS_CLOSE_PARAMETER = '%facts_close%';
    private const string ENGLISH = 'en';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly FactFormatter $factFormatter,
        private readonly LocaleSettings $localeSettings,
        private readonly float $temperature,
        private readonly int $maxOutputTokens
    ) {
    }

    /**
     * @param string $entityType The provider's {@see SummaryFactProviderInterface::getEntityType()}, which selects
     *                           the translation `oro_smart_brief.prompt.{entityType}`
     *
     * The language code is Oro's own, region-qualified when the localization is (`de_DE`); it is passed unchanged.
     * A prompt translation is looked up under that code, with Oro's localization fallback.
     */
    public function build(string $entityType, FactSet $facts): SummaryRequest
    {
        $languageCode = $this->localeSettings->getLanguage();
        // the delimiters are parameters: Oro's translation sanitizer strips tag-like text from translated messages
        $parameters = [
            self::LANGUAGE_PARAMETER => $this->getEnglishLanguageName($languageCode),
            self::FACTS_OPEN_PARAMETER => FactFormatter::FACTS_OPEN,
            self::FACTS_CLOSE_PARAMETER => FactFormatter::FACTS_CLOSE,
        ];

        $systemPrompt = $this->translator->trans(self::PROMPT_COMMON_KEY, $parameters)
            . "\n\n"
            . $this->translator->trans(sprintf(self::PROMPT_ENTITY_KEY_PATTERN, $entityType), $parameters);

        return new SummaryRequest(
            $systemPrompt,
            $this->factFormatter->formatFactsBlock($facts),
            $languageCode,
            $this->temperature,
            $this->maxOutputTokens
        );
    }

    /**
     * The language's English display name ("German"), which the prompt uses to name the reply language.
     * Oro language codes are region-qualified (`de_DE`), so the primary language is looked up; an unknown
     * code is used as it is.
     */
    private function getEnglishLanguageName(string $languageCode): string
    {
        try {
            return Languages::getName(\Locale::getPrimaryLanguage($languageCode) ?: $languageCode, self::ENGLISH);
        } catch (MissingResourceException) {
            return $languageCode;
        }
    }
}
