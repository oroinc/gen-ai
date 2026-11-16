<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Prompt;

use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\CustomerBundle\Tests\Functional\DataFixtures\LoadCustomers;
use Oro\Bundle\LocaleBundle\Tests\Functional\DataFixtures\LoadLocalizationData;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadGermanLocalizationData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadOpenAiChannelData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\CustomerFactProviderStub;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\OpenAiSummaryClientFactoryStub;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Covers the prompt language end to end through the test AI client: for a user
 * whose localization has no prompt translation (Spanish `es`, and German as administrators create it, `de_DE`),
 * the English prompts are sent with the reply language named, and the request carries the localization's language
 * code. The prompt wording itself is checked by hand against the real providers.
 */
final class PromptLanguageTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([
            LoadCustomers::class,
            LoadOpenAiChannelData::class,
            LoadLocalizationData::class,
            LoadGermanLocalizationData::class,
        ]);
        $this->updateUserSecurityToken(self::AUTH_USER);
        $this->setConfigValue('oro_smart_brief.enabled', true);
        $this->setConfigValue(
            'oro_smart_brief.integration',
            $this->getReference(LoadOpenAiChannelData::CHANNEL)->getId()
        );
        $this->setConfigValue('oro_smart_brief.customer_enabled', true);
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
        CustomerFactProviderStub::enable();
        OpenAiSummaryClientFactoryStub::reset();
        OpenAiSummaryClientFactoryStub::respondWith(new SummaryResponse('Resumen.', 'stub-model', 10, 5));
        self::getContainer()->get('oro_smart_brief.tests.cache')->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CustomerFactProviderStub::reset();
        OpenAiSummaryClientFactoryStub::reset();
        $this->restoreConfigValues();
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
        self::getContainer()->get('oro_smart_brief.tests.cache')->clear();
        parent::tearDown();
    }

    /**
     * @dataProvider localizationDataProvider
     */
    public function testRequestInTheUsersLanguageWithTheEnglishPromptFallback(
        string $localizationReference,
        string $languageCode,
        string $languageName
    ): void {
        $this->setConfigValue(
            'oro_locale.default_localization',
            $this->getReference($localizationReference)->getId(),
            'user'
        );
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        self::assertFalse(
            $translator->getCatalogue($languageCode)->defines('oro_smart_brief.prompt.common'),
            'The case needs a language without its own prompt translation.'
        );

        $view = self::getContainer()->get('oro_smart_brief.tests.generator')
            ->generate($this->getReference(LoadCustomers::CUSTOMER_LEVEL_1_1));

        self::assertNotNull($view);
        self::assertFalse($view->fallback);
        self::assertSame('Resumen.', $view->text);
        $requests = OpenAiSummaryClientFactoryStub::getLastClient()?->getRequests() ?? [];
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame($languageCode, $request->languageCode);
        $parameters = [
            '%language%' => $languageName,
            '%facts_open%' => FactFormatter::FACTS_OPEN,
            '%facts_close%' => FactFormatter::FACTS_CLOSE,
        ];
        self::assertSame(
            $translator->trans('oro_smart_brief.prompt.common', $parameters, null, 'en')
            . "\n\n"
            . $translator->trans('oro_smart_brief.prompt.customer', $parameters, null, 'en'),
            $request->systemPrompt,
            'The English prompts, common rules first, with the reply language named.'
        );
        self::assertStringContainsString('Reply in ' . $languageName . ';', $request->systemPrompt);
        // the delimiters survive the platform's translation sanitizer
        self::assertStringContainsString(
            'between ' . FactFormatter::FACTS_OPEN . ' and ' . FactFormatter::FACTS_CLOSE . '.',
            $request->systemPrompt
        );
        self::assertStringNotContainsString('%facts_', $request->systemPrompt);
        self::assertStringStartsWith("<facts>\n", $request->userContent);
        self::assertStringEndsWith('</facts>', $request->userContent);
    }

    public static function localizationDataProvider(): array
    {
        return [
            'Spanish' => [LoadLocalizationData::ES_LOCALIZATION_CODE, 'es', 'Spanish'],
            'German, region-qualified' => [LoadGermanLocalizationData::LOCALIZATION, 'de_DE', 'German'],
        ];
    }
}
