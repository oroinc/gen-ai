<?php

namespace Oro\Bundle\AiContentGenerationBundle\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\ProductBundle\Entity\Product;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Provides extended attributes for the Entity object
 */
class ProductAttributesProvider
{
    public function __construct(
        private readonly ConfigProvider $entityConfigProvider,
        private readonly ExtendConfigsProvider $extendConfigsProvider,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function getAttributes(Product $product, array $formData): array
    {
        $results = [];
        $brand = (string)$product->getBrand();

        if ($brand) {
            $results[] = new ContextItem(
                $this->translator->trans(
                    'oro_ai_content_generation.form.context.product.brand_name.label'
                ),
                $brand
            );
        }

        $fields = $this->extendConfigsProvider->getAttributes(Product::class);

        foreach ($fields as $field) {
            $fieldConfigId = $field->getId();
            $fieldName = $fieldConfigId->getFieldName();

            $fieldConfig = $this->entityConfigProvider->getConfigById($fieldConfigId);

            if (!array_key_exists($fieldName, $formData) || !$formData[$fieldName]) {
                continue;
            }

            $results[] = new ContextItem(
                $this->translator->trans($fieldConfig->get('label') ?: $fieldName),
                $formData[$fieldName]
            );
        }

        return $results;
    }
}
