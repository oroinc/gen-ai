<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ExtendConfigsProvider;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductAttributesProvider;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityExtendBundle\Extend\FieldTypeHelper;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\ProductBundle\Entity\Brand;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductAttributesProviderTest extends TestCase
{
    use EntityTrait;

    private ConfigProvider&MockObject $extendConfigProvider;

    private ConfigProvider&MockObject $viewConfigProvider;

    private FieldTypeHelper&MockObject $fieldTypeHelper;

    private FeatureChecker&MockObject $featureChecker;

    private ConfigProvider&MockObject $entityConfigProvider;

    private TranslatorInterface&MockObject $translator;

    private ProductAttributesProvider $productAttributesProvider;

    private ExtendConfigsProvider $extendConfigsProvider;

    private Product $product;

    private Brand&MockObject $brand;

    protected function setUp(): void
    {
        $this->entityConfigProvider = $this->createMock(ConfigProvider::class);
        $this->extendConfigsProvider = $this->createMock(ExtendConfigsProvider::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->productAttributesProvider = new ProductAttributesProvider(
            $this->entityConfigProvider,
            $this->extendConfigsProvider,
            $this->translator
        );
    }

    public function testGetAttributes(): void
    {
        $product = $this->createMock(Product::class);
        $product
            ->expects(self::any())
            ->method('getBrand')
            ->willReturn('TestBrand');

        $formData = [
            'field1' => 'value1',
            'field3' => 'value3'
        ];

        $fieldConfig = $this->createMock(ConfigInterface::class);
        $fieldConfigId = $this->createMock(FieldConfigId::class);

        $fieldConfigId
            ->expects(self::any())
            ->method('getFieldName')
            ->willReturnOnConsecutiveCalls('field1', 'field2');

        $fieldConfig
            ->method('getId')
            ->willReturn($fieldConfigId);

        $fieldConfig
            ->method('get')
            ->with('label')
            ->willReturnOnConsecutiveCalls('Field 1', 'Field 2');

        $this->extendConfigsProvider
            ->method('getAttributes')
            ->with(Product::class)
            ->willReturn(new \ArrayIterator([$fieldConfig, $fieldConfig]));

        $this->entityConfigProvider
            ->expects(self::any())
            ->method('getConfigById')
            ->willReturn($fieldConfig);

        $this->translator
            ->method('trans')
            ->willReturnMap([
                ['oro_ai_content_generation.form.context.product.brand_name.label', [], null, null, 'Brand Name'],
                ['Field 1', [], null, null, 'Field 1'],
                ['Field 2', [], null, null, 'Field 2']
            ]);

        $expectedResults = [
            new ContextItem('Brand Name', 'TestBrand'),
            new ContextItem('Field 1', 'value1'),
        ];

        $results = $this->productAttributesProvider->getAttributes($product, $formData);

        self::assertEquals($expectedResults, $results);
    }
}
