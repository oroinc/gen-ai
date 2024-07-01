<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\OpenAiTransportSettingsType;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\FormBundle\Form\Extension\TooltipFormExtension;
use Oro\Bundle\LocaleBundle\Entity\Localization;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\LocaleBundle\Form\Type\FallbackPropertyType;
use Oro\Bundle\LocaleBundle\Form\Type\FallbackValueType;
use Oro\Bundle\LocaleBundle\Form\Type\LocalizationCollectionType;
use Oro\Bundle\LocaleBundle\Form\Type\LocalizedFallbackValueCollectionType;
use Oro\Bundle\LocaleBundle\Form\Type\LocalizedPropertyType;
use Oro\Bundle\LocaleBundle\Tests\Unit\Form\Type\Stub\LocalizationCollectionTypeStub;
use Oro\Bundle\TranslationBundle\Translation\Translator;
use Oro\Component\Testing\Unit\EntityTrait;
use Oro\Component\Testing\Unit\FormIntegrationTestCase;
use Oro\Component\Testing\Unit\PreloadedExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

final class OpenAiTransportSettingsTypeTest extends FormIntegrationTestCase
{
    use EntityTrait;

    private const int LOCALIZATION_ID = 998;

    protected function getExtensions(): array
    {
        $repositoryLocalization = $this->createMock(ObjectRepository::class);
        $repositoryLocalization->expects(self::any())
            ->method('find')
            ->willReturnCallback(function ($id) {
                return $this->getEntity(Localization::class, ['id' => $id]);
            });

        $repositoryLocalizedFallbackValue = $this->createMock(ObjectRepository::class);
        $repositoryLocalizedFallbackValue->expects(self::any())
            ->method('find')
            ->willReturnCallback(function ($id) {
                return $this->getEntity(LocalizedFallbackValue::class, ['id' => $id]);
            });

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::any())
            ->method('getRepository')
            ->willReturnMap([
                [Localization::class, null, $repositoryLocalization],
                [LocalizedFallbackValue::class, null, $repositoryLocalizedFallbackValue],
            ]);

        $translator = $this->createMock(Translator::class);

        return [
            new PreloadedExtension(
                [
                    LocalizedPropertyType::class => new LocalizedPropertyType(),
                    LocalizedFallbackValueCollectionType::class => new LocalizedFallbackValueCollectionType($doctrine),
                    LocalizationCollectionType::class => new LocalizationCollectionTypeStub([
                        $this->getEntity(Localization::class, ['id' => self::LOCALIZATION_ID]),
                    ]),
                    FallbackValueType::class => new FallbackValueType(),
                    FallbackPropertyType::class => new FallbackPropertyType($translator),
                ],
                [
                    FormType::class => [
                        new TooltipFormExtension($this->createMock(ConfigProvider::class), $translator),
                    ],
                ]
            ),
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    public function testGetBlockPrefixReturnsCorrectString(): void
    {
        $formType = new OpenAiTransportSettingsType();
        self::assertEquals('oro_open_ai_settings', $formType->getBlockPrefix());
    }

    public function testConfigureOptions(): void
    {
        $form = $this->factory->create(OpenAiTransportSettingsType::class);

        self::assertEquals(
            OpenAiTransportSettings::class,
            $form->getConfig()->getOption('data_class')
        );
    }

    public function testSubmit(): void
    {
        $openAiSettings = new OpenAiTransportSettings();
        $openAiSettings
            ->setToken('some token')
            ->addLabel($this->createLocalizedValue(
                'Label 1',
                null,
                $this->getEntity(Localization::class, ['id' => self::LOCALIZATION_ID])
            ))
            ->addLabel($this->createLocalizedValue('Label 1'));

        $submitData = [
            'token' => 'some token',
            'model' => OpenAiTransportSettings::DEFAULT_MODEL,
            'labels' => [
                'values' => [
                    'default' => 'Label 1',
                    'localizations' => [
                        self::LOCALIZATION_ID => [
                            'value' => 'Label 1',
                        ],
                    ],
                ],
            ],
        ];

        $form = $this->factory->create(OpenAiTransportSettingsType::class);
        $form->submit($submitData);

        self::assertTrue($form->isValid());
        self::assertTrue($form->isSynchronized());
        self::assertEquals($openAiSettings, $form->getData());
    }

    private function createLocalizedValue(
        ?string $string = null,
        ?string $text = null,
        ?Localization $localization = null
    ): LocalizedFallbackValue {
        $value = new LocalizedFallbackValue();
        $value->setString($string)
            ->setText($text)
            ->setLocalization($localization);

        return $value;
    }
}
