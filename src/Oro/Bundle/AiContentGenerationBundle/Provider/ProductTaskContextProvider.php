<?php

namespace Oro\Bundle\AiContentGenerationBundle\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Form\Type\ProductType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Provides contexts for the product tasks
 */
class ProductTaskContextProvider
{
    public function __construct(
        private readonly EntityFormResolver $entityFormResolver,
        private readonly LocalizationProvider $localizationProvider,
        private readonly TranslatorInterface $translator,
        private readonly ProductAttributesProvider $productAttributesProvider
    ) {
    }

    public function getDescription(
        UserContentGenerationRequest $contentGenerationRequest,
        string $submittedFormFieldPluralForm,
        string $localizedFormFieldPluralForm = null
    ): ?ContextItem {
        /**
         * @var Product $product
         */
        $product = $this->entityFormResolver->resolve(
            ProductType::class,
            new Product(),
            $contentGenerationRequest->getSubmittedFormData()
        );

        $localization = $this->localizationProvider->getLocalizationFromSubmittedField(
            ProductType::NAME,
            $localizedFormFieldPluralForm ?? $submittedFormFieldPluralForm,
            $contentGenerationRequest->getSubmittedFormField()
        );

        $content = trim(strip_tags((string)$product->getDescription($localization)));
        if (!$content) {
            return null;
        }

        return new ContextItem(
            $this->translator->trans(
                'oro_ai_content_generation.form.context.product.description.label'
            ),
            $content
        );
    }

    public function getAttributes(UserContentGenerationRequest $contentGenerationRequest): array
    {
        /**
         * @var Product $product
         */
        $product = $this->entityFormResolver->resolve(
            ProductType::class,
            new Product(),
            $contentGenerationRequest->getSubmittedFormData()
        );

        $formData = $contentGenerationRequest->getSubmittedFormData();

        return $this->productAttributesProvider->getAttributes($product, $formData);
    }

    public function getFullContext(
        UserContentGenerationRequest $contentGenerationRequest,
        string $submittedFormFieldPluralForm
    ): array {
        /**
         * @var Product $product
         */
        $product = $this->entityFormResolver->resolve(
            ProductType::class,
            new Product(),
            $contentGenerationRequest->getSubmittedFormData()
        );

        $localization = $this->localizationProvider->getLocalizationFromSubmittedField(
            ProductType::NAME,
            $submittedFormFieldPluralForm,
            $contentGenerationRequest->getSubmittedFormField()
        );

        $contextItems = [
            new ContextItem(
                $this->translator->trans(
                    'oro_ai_content_generation.form.context.product.name.label'
                ),
                (string)$product->getName($localization)
            ),
            new ContextItem(
                $this->translator->trans(
                    'oro_ai_content_generation.form.context.product.keywords.label'
                ),
                (string)$product->getMetaKeyword($localization)
            ),
            new ContextItem(
                $this->translator->trans(
                    'oro_ai_content_generation.form.context.product.sku.label'
                ),
                (string)$product->getSku()
            ),
            ...$this->productAttributesProvider->getAttributes(
                $product,
                $contentGenerationRequest->getSubmittedFormData()
            ),
            new ContextItem(
                $this->translator->trans(
                    'oro_ai_content_generation.form.context.product.category_title.label'
                ),
                (string)$product->getCategory()?->getTitle($localization)
            )
        ];

        return array_filter($contextItems, fn (ContextItem $contextItem) => $contextItem->getValue());
    }
}
