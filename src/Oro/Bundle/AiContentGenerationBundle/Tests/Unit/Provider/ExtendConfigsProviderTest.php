<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Provider;

use Oro\Bundle\AiContentGenerationBundle\Provider\ExtendConfigsProvider;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Extend\FieldTypeHelper;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\ProductBundle\Entity\Brand;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExtendConfigsProviderTest extends TestCase
{
    use EntityTrait;

    private ConfigProvider&MockObject $extendConfigProvider;

    private ConfigProvider&MockObject $viewConfigProvider;

    private FieldTypeHelper&MockObject $fieldTypeHelper;

    private FeatureChecker&MockObject $featureChecker;

    private ConfigProvider&MockObject $entityConfigProvider;

    private TranslatorInterface&MockObject $translator;

    private ExtendConfigsProvider $extendConfigsProvider;

    private Product $product;

    private Brand&MockObject $brand;

    #[\Override]
    protected function setUp(): void
    {
        $this->extendConfigProvider = $this->createMock(ConfigProvider::class);
        $this->viewConfigProvider = $this->createMock(ConfigProvider::class);
        $this->fieldTypeHelper = $this->createMock(FieldTypeHelper::class);
        $this->featureChecker = $this->createMock(FeatureChecker::class);

        $this->extendConfigsProvider = new ExtendConfigsProvider(
            $this->extendConfigProvider,
            $this->viewConfigProvider,
            $this->fieldTypeHelper,
            $this->featureChecker,
        );
    }

    /**
     * @dataProvider fieldDataProvider
     */
    public function testThatSystemAndNotAccessibleFieldSkipped(ConfigInterface $fieldConfig): void
    {
        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        self::assertEmpty(
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    public function testThatInvisibleFieldSkipped(): void
    {
        $fieldConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'is_extend' => false
                ]
            ]
        );

        $viewConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'owner' => 'custom',
                    'is_displayable' => false
                ]
            ]
        );

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        $this->viewConfigProvider
            ->expects(self::once())
            ->method('getConfigById')
            ->willReturn($viewConfig);

        self::assertEmpty(
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    /**
     * @dataProvider invalidTargetEntityProvider
     */
    public function testThatFieldWithInvalidTargetEntitySkipped(
        ConfigInterface $fieldConfig,
        ConfigInterface $viewConfig
    ): void {
        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        $this->viewConfigProvider
            ->expects(self::once())
            ->method('getConfigById')
            ->willReturn($viewConfig);

        $this->featureChecker
            ->expects(self::any())
            ->method('isResourceEnabled')
            ->willReturn(false);

        self::assertEmpty(
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    public function testThatFieldSkippedWithRelationsReferencedToNotAccessibleEntity(): void
    {
        $fieldConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'is_extend' => false,
                    'target_entity' => \StdClass::class
                ]
            ]
        );

        $viewConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'is_displayable' => true,
                ]
            ]
        );

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        $this->viewConfigProvider
            ->expects(self::once())
            ->method('getConfigById')
            ->willReturn($viewConfig);

        $this->featureChecker
            ->expects(self::once())
            ->method('isResourceEnabled')
            ->willReturn(true);

        $this->fieldTypeHelper
            ->expects(self::once())
            ->method('getUnderlyingType')
            ->willReturn(RelationType::MANY_TO_MANY);

        self::assertEmpty(
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    public function testThatFieldWithNotAccessibleEntitySkipped(): void
    {
        $fieldConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'is_extend' => false,
                    'target_entity' => \StdClass::class
                ]
            ]
        );

        $viewConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'is_displayable' => true,
                ]
            ]
        );

        $entityConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'is_extend' => true,
                    'is_deleted' => true
                ]
            ]
        );

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        $this->viewConfigProvider
            ->expects(self::once())
            ->method('getConfigById')
            ->willReturn($viewConfig);

        $this->featureChecker
            ->expects(self::once())
            ->method('isResourceEnabled')
            ->willReturn(true);

        $this->fieldTypeHelper
            ->expects(self::once())
            ->method('getUnderlyingType')
            ->willReturn('valid');

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfig')
            ->with(\StdClass::class)
            ->willReturn($entityConfig);

        self::assertEmpty(
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    public function testGetAttributesWithValidFields()
    {
        $fieldConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'is_extend' => false,
                    'target_entity' => \StdClass::class
                ]
            ]
        );

        $viewConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'is_displayable' => true,
                ]
            ]
        );

        $entityConfig = $this->getEntity(
            Config::class,
            constructorArgs: [
                'id' => $this->getEntity(
                    FieldConfigId::class,
                    constructorArgs: ['', '', '']
                ),
                'values' => [
                    'is_extend' => false
                ]
            ]
        );

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfigs')
            ->with(\StdClass::class)
            ->willReturn([$fieldConfig]);

        $this->viewConfigProvider
            ->expects(self::once())
            ->method('getConfigById')
            ->willReturn($viewConfig);

        $this->featureChecker
            ->expects(self::once())
            ->method('isResourceEnabled')
            ->willReturn(true);

        $this->fieldTypeHelper
            ->expects(self::once())
            ->method('getUnderlyingType')
            ->willReturn('valid');

        $this->extendConfigProvider
            ->expects(self::once())
            ->method('getConfig')
            ->with(\StdClass::class)
            ->willReturn($entityConfig);

        self::assertEquals(
            [$fieldConfig],
            iterator_to_array($this->extendConfigsProvider->getAttributes(\StdClass::class))
        );
    }

    private function fieldDataProvider(): array
    {
        return [
            'system field' => [
                'fieldConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => ['owner' => 'system']
                    ]
                )
            ],
            'not accessible' => [
                'fieldConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => [
                            'owner' => ExtendScope::OWNER_CUSTOM,
                            'is_extend' => true,
                            'is_deleted' => true
                        ]
                    ]
                )
            ]
        ];
    }

    private function invalidTargetEntityProvider(): array
    {
        return [
            'no target entity' => [
                'fieldConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => [
                            'owner' => ExtendScope::OWNER_CUSTOM,
                            'is_extend' => false,
                        ]
                    ]
                ),
                'viewConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => [
                            'is_displayable' => true,
                        ]
                    ]
                )
            ],
            'invalid target entity' => [
                'fieldConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => [
                            'owner' => ExtendScope::OWNER_CUSTOM,
                            'is_extend' => false,
                            'target_entity' => \StdClass::class
                        ]
                    ]
                ),
                'viewConfig' => $this->getEntity(
                    Config::class,
                    constructorArgs: [
                        'id' => $this->getEntity(
                            FieldConfigId::class,
                            constructorArgs: ['', '', '']
                        ),
                        'values' => [
                            'is_displayable' => true,
                        ]
                    ]
                )
            ]
        ];
    }
}
