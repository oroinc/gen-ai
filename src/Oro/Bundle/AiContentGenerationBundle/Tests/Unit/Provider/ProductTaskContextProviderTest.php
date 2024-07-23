<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\AiContentGenerationBundle\Provider\LocalizationProvider;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductAttributesProvider;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\CatalogBundle\Entity\Category;
use Oro\Bundle\CatalogBundle\Entity\CategoryTitle;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Entity\ProductName;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductTaskContextProviderTest extends TestCase
{
    private EntityFormResolver&MockObject $entityFormResolver;

    private LocalizationProvider&MockObject $localizationProvider;

    private TranslatorInterface&MockObject $translator;

    private ProductAttributesProvider&MockObject $productAttributesProvider;

    private ProductTaskContextProvider $productTaskContextProvider;

    protected function setUp(): void
    {
        $this->entityFormResolver = $this->createMock(EntityFormResolver::class);
        $this->localizationProvider = $this->createMock(LocalizationProvider::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->productAttributesProvider = $this->createMock(ProductAttributesProvider::class);

        $this->productTaskContextProvider = new ProductTaskContextProvider(
            $this->entityFormResolver,
            $this->localizationProvider,
            $this->translator,
            $this->productAttributesProvider
        );
    }

    public function testGetDescription(): void
    {
        $product = $this->getMockBuilder(Product::class)->addMethods(['getDescription'])->getMock();
        $contentGenerationRequest = new UserContentGenerationRequest(
            '',
            [],
            '',
            []
        );

        $this->entityFormResolver
            ->method('resolve')
            ->willReturn($product);

        $product
            ->expects(self::any())
            ->method('getDescription')
            ->willReturn('<p> Description Text</p>');

        $submittedFormFieldPluralForm = 'field';

        $this->translator
            ->method('trans')
            ->willReturn('Description');

        $expected = new ContextItem('Description', 'Description Text');

        $result = $this->productTaskContextProvider->getDescription(
            $contentGenerationRequest,
            $submittedFormFieldPluralForm
        );

        self::assertEquals($expected, $result);
    }

    public function testGetAttributes(): void
    {
        $product = $this->createMock(Product::class);
        $contentGenerationRequest = new UserContentGenerationRequest(
            '',
            [],
            '',
            []
        );

        $this->entityFormResolver
            ->expects(self::once())
            ->method('resolve')
            ->willReturn($product);

        $expectedAttributes = [new ContextItem('Field', 'value')];

        $this->productAttributesProvider
            ->expects(self::once())
            ->method('getAttributes')
            ->willReturn($expectedAttributes);

        $result = $this->productTaskContextProvider->getAttributes($contentGenerationRequest);

        self::assertEquals($expectedAttributes, $result);
    }

    public function testGetFullContext(): void
    {
        $contentGenerationRequest = new UserContentGenerationRequest(
            '',
            [],
            '',
            []
        );

        $categoryTitle = new CategoryTitle();
        $categoryTitle->setString('CategoryTitle');

        $productName = new ProductName();
        $productName->setString('ProductName');

        $keyword = new LocalizedFallbackValue();
        $keyword->setString('Keywords');

        $category = $this->getMockBuilder(Category::class)
            ->addMethods(['getTitle'])
            ->getMock();

        $product = $this->getMockBuilder(Product::class)
            ->addMethods(['getName', 'getMetaKeyword', 'getCategory'])
            ->onlyMethods(['getSku'])
            ->getMock();

        $product
            ->expects(self::any())
            ->method('getName')
            ->willReturn($productName);

        $product
            ->expects(self::any())
            ->method('getMetaKeyword')
            ->willReturn($keyword);

        $product
            ->expects(self::any())
            ->method('getSku')
            ->willReturn('SKU');

        $category
            ->expects(self::any())
            ->method('getTitle')
            ->willReturn($categoryTitle);

        $product
            ->expects(self::any())
            ->method('getCategory')
            ->willReturn($category);

        $this->entityFormResolver
            ->method('resolve')
            ->willReturn($product);

        $this->translator->method('trans')->willReturnMap([
            ['oro_ai_content_generation.form.context.product.name.label', [], null, null, 'Name'],
            ['oro_ai_content_generation.form.context.product.keywords.label', [], null, null, 'Keywords'],
            ['oro_ai_content_generation.form.context.product.sku.label', [], null, null, 'SKU'],
            ['oro_ai_content_generation.form.context.product.category_title.label', [], null, null, 'Category']
        ]);

        $attributes = [new ContextItem('Field', 'value')];

        $this->productAttributesProvider
            ->expects(self::once())
            ->method('getAttributes')
            ->willReturn($attributes);

        $expected = [
            new ContextItem('Name', 'ProductName'),
            new ContextItem('Keywords', 'Keywords'),
            new ContextItem('SKU', 'SKU'),
            ...$attributes,
            new ContextItem('Category', 'CategoryTitle')
        ];

        $result = $this->productTaskContextProvider->getFullContext($contentGenerationRequest, 'plural');

        self::assertEquals($expected, $result);
    }
}
