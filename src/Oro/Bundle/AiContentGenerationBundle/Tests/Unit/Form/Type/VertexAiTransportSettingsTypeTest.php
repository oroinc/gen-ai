<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Form\Type\VertexAiTransportSettingsType;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Form\DataTransformer\ContentFileDataTransformer;
use Oro\Bundle\AttachmentBundle\Form\Type\ContentFileType;
use Oro\Bundle\AttachmentBundle\Form\Type\FileType;
use Oro\Bundle\AttachmentBundle\Tools\ExternalFileFactory;
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
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\HttpFoundation\File\File as HttpFile;
use Symfony\Component\Validator\Validation;

final class VertexAiTransportSettingsTypeTest extends FormIntegrationTestCase
{
    use EntityTrait;

    private const LOCALIZATION_ID = 998;

    private ContentFileDataTransformer|MockObject $modelDataTransformer;

    protected function getExtensions(): array
    {
        $this->modelDataTransformer = $this->createMock(ContentFileDataTransformer::class);

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
                    new ContentFileType($this->modelDataTransformer),
                    new FileType($this->createMock(ExternalFileFactory::class))
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
        $formType = new VertexAiTransportSettingsType();
        self::assertEquals('oro_vertex_ai_settings', $formType->getBlockPrefix());
    }

    public function testConfigureOptions(): void
    {
        $form = $this->factory->create(VertexAiTransportSettingsType::class);

        self::assertEquals(
            VertexAiTransportSettings::class,
            $form->getConfig()->getOption('data_class')
        );
    }

    public function testSubmit(): void
    {
        $this->modelDataTransformer->expects(self::once())
            ->method('reverseTransform')
            ->willReturn('content');

        $file = new File();
        $file->setEmptyFile(false);
        $file->setFile(new HttpFile('config.json', false));

        $vertexAiSettings = new VertexAiTransportSettings();
        $vertexAiSettings
            ->setConfigFile('content')
            ->setApiEndpoint('/endpoint')
            ->setProjectId('12345')
            ->addLabel($this->createLocalizedValue(
                'Label 1',
                null,
                $this->getEntity(Localization::class, ['id' => self::LOCALIZATION_ID])
            ))
            ->addLabel($this->createLocalizedValue('Label 1'));

        $submitData = [
            'configFile' => ['file' => $file, 'emptyFile' => ''],
            'apiEndpoint' => '/endpoint',
            'projectId' => '12345',
            'location' => VertexAiTransportSettings::DEFAULT_LOCATION,
            'model' => VertexAiTransportSettings::DEFAULT_MODEL,
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

        $form = $this->factory->create(VertexAiTransportSettingsType::class);
        $form->submit($submitData);

        self::assertTrue($form->isValid());
        self::assertTrue($form->isSynchronized());
        self::assertEquals($vertexAiSettings, $form->getData());
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
