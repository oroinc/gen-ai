<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Rfq;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\ProductBundle\Entity\ProductUnit;
use Oro\Bundle\ProductBundle\Tests\Unit\Entity\Stub\Product;
use Oro\Bundle\RFPBundle\Entity\RequestProduct;
use Oro\Bundle\RFPBundle\Entity\RequestProductItem;
use Oro\Component\Testing\ReflectionUtil;

/**
 * Builds RFQ items as the item source loads them: the item with its request product and product (one product
 * object per product id).
 */
trait RfqItemTestTrait
{
    /** @var array<int, Product> */
    private array $products = [];

    private function item(
        int $requestProductId,
        int $itemId,
        int $productId,
        string $price,
        string $currency,
        string $unit,
        string $sku,
        string $quantity = '1',
        bool $isKit = false
    ): RequestProductItem {
        $product = $this->products[$productId] ??= (new Product())->setSku($sku);
        ReflectionUtil::setId($product, $productId);
        $product->setType($isKit ? Product::TYPE_KIT : Product::TYPE_SIMPLE);
        $requestProduct = (new RequestProduct())->setProduct($product);
        ReflectionUtil::setId($requestProduct, $requestProductId);
        $item = (new RequestProductItem())
            ->setRequestProduct($requestProduct)
            ->setQuantity((float)$quantity)
            ->setProductUnit((new ProductUnit())->setCode($unit))
            ->setPrice(Price::create($price, $currency));
        ReflectionUtil::setId($item, $itemId);

        return $item;
    }
}
