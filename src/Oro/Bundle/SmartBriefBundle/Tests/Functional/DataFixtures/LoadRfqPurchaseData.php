<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CurrencyBundle\Entity\MultiCurrency;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOption;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadOrderUsers;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Entity\ProductUnit;
use Oro\Bundle\ProductBundle\Tests\Functional\DataFixtures\LoadProductData;
use Oro\Bundle\ProductBundle\Tests\Functional\DataFixtures\LoadProductUnits;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\RFPBundle\Entity\RequestProduct;
use Oro\Bundle\RFPBundle\Entity\RequestProductItem;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Orders and RFQs for the RFQ purchase tests. Customer "Litware" requests, in RFQ_PURCHASE, product-1 (twice),
 * product-2, product-3 and a product that was deleted — 3 requested products. Its orders, dated in the previous
 * calendar year ({@see lastYear()}):
 * - closed, product-1, 08-14 03:00 UTC, owned by another user (the latest purchase; 08-13 in Los Angeles);
 * - closed, product-1 again, 05-01, owned by another user (the same product counts once);
 * - open, product-2, 07-01 10:00 UTC (the latest the test user's own orders show);
 * - cancelled, product-3, 09-01 (not a purchase);
 * - a cancelled top-level order (06-01) whose closed sub-order (09-05) has product-3; as in real
 *   multi-shipping the line item belongs to both orders, and the parent's status decides: not a purchase.
 * Its child customer "Litware East" and customer "Proseware" have closed orders with product-3 (not this
 * customer).
 * RFQ_NO_PURCHASE requests only product-6, never bought. RFQ_ONE_LINE and RFQ_FIFTY_LINES (product-1..3) are for
 * the query-count test.
 */
class LoadRfqPurchaseData extends AbstractFixture implements DependentFixtureInterface
{
    public const string RFQ_PURCHASE = 'smart_brief.rfq_purchase.rfq';
    public const string RFQ_NO_PURCHASE = 'smart_brief.rfq_purchase.rfq.none';
    public const string RFQ_ONE_LINE = 'smart_brief.rfq_purchase.rfq.one_line';
    public const string RFQ_FIFTY_LINES = 'smart_brief.rfq_purchase.rfq.fifty_lines';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOrganization::class, LoadUser::class, LoadOrderUsers::class, LoadProductData::class];
    }

    /**
     * A date of the previous calendar year: always in the past and within the purchase history period.
     */
    public static function lastYear(string $monthDayTime): string
    {
        return ((int)date('Y') - 1) . '-' . $monthDayTime;
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        /** @var Organization $organization */
        $organization = $this->getReference(LoadOrganization::ORGANIZATION);
        /** @var User $user */
        $user = $this->getReference(LoadUser::USER);
        /** @var User $other */
        $other = $this->getReference(LoadOrderUsers::ORDER_USER_1);
        $litware = $this->createCustomerUser($manager, 'Litware', $organization, $user);
        $proseware = $this->createCustomerUser($manager, 'Proseware', $organization, $user);
        $products = array_map(
            fn (string $sku) => $this->getReference($sku),
            [
                1 => LoadProductData::PRODUCT_1,
                2 => LoadProductData::PRODUCT_2,
                3 => LoadProductData::PRODUCT_3,
                6 => LoadProductData::PRODUCT_6,
            ]
        );
        $liter = $this->getReference(LoadProductUnits::LITER);

        $this->createRfq(
            $manager,
            self::RFQ_PURCHASE,
            $litware,
            $user,
            $liter,
            [$products[1], $products[2], $products[3], $products[1], null]
        );
        $this->createRfq($manager, self::RFQ_NO_PURCHASE, $litware, $user, $liter, [$products[6]]);
        $this->createRfq($manager, self::RFQ_ONE_LINE, $litware, $user, $liter, [$products[1]]);
        $fifty = [];
        for ($line = 0; $line < 50; $line++) {
            $fifty[] = $products[$line % 3 + 1];
        }
        $this->createRfq($manager, self::RFQ_FIFTY_LINES, $litware, $user, $liter, $fifty);

        $customer = $litware->getCustomer();
        $child = $this->createCustomerUser($manager, 'Litware East', $organization, $user)->getCustomer();
        $child->setParent($customer);
        $this->createOrder($manager, $customer, $other, 'closed', '08-14 03:00:00', $products[1], $liter);
        $this->createOrder($manager, $customer, $other, 'closed', '05-01 10:00:00', $products[1], $liter);
        $this->createOrder($manager, $customer, $user, 'open', '07-01 10:00:00', $products[2], $liter);
        $this->createOrder($manager, $customer, $user, 'cancelled', '09-01 10:00:00', $products[3], $liter);
        $parent = $this->createOrder($manager, $customer, $user, 'cancelled', '06-01 10:00:00', null, $liter);
        $subOrderDate = '09-05 10:00:00';
        $this->createOrder($manager, $customer, $user, 'closed', $subOrderDate, $products[3], $liter, $parent);
        $this->createOrder($manager, $child, $user, 'closed', '09-08 10:00:00', $products[3], $liter);
        $proseware = $proseware->getCustomer();
        $this->createOrder($manager, $proseware, $user, 'closed', '09-10 10:00:00', $products[3], $liter);

        $manager->flush();
    }

    private function createCustomerUser(
        ObjectManager $manager,
        string $name,
        Organization $organization,
        User $owner
    ): CustomerUser {
        $customer = (new Customer())->setName($name)->setOrganization($organization)->setOwner($owner);
        $manager->persist($customer);
        $customerUser = (new CustomerUser())
            ->setFirstName('Amanda')
            ->setLastName('Cole')
            ->setEmail(md5('smart_brief.rfq_purchase.' . $name) . '@example.com')
            ->setPassword('not-used')
            ->setIsGuest(false)
            ->setCustomer($customer)
            ->setOwner($owner)
            ->setOrganization($organization);
        $manager->persist($customerUser);

        return $customerUser;
    }

    /**
     * @param array<int, Product|null> $products one line per entry; null is a line whose product was deleted
     */
    private function createRfq(
        ObjectManager $manager,
        string $reference,
        CustomerUser $customerUser,
        User $owner,
        ProductUnit $unit,
        array $products
    ): void {
        $rfq = (new Request())
            ->setFirstName('Amanda')
            ->setLastName('Cole')
            ->setEmail('amanda.cole@example.com')
            ->setCompany($customerUser->getCustomer()->getName())
            ->setCustomerUser($customerUser)
            ->setCustomer($customerUser->getCustomer())
            ->setOrganization($customerUser->getOrganization())
            ->setOwner($owner);
        foreach ($products as $product) {
            $rfq->addRequestProduct(
                (new RequestProduct())
                    ->setProduct($product)
                    ->setProductSku($product?->getSku() ?? 'DELETED-SKU')
                    ->addRequestProductItem(
                        (new RequestProductItem())
                            ->setQuantity(1)
                            ->setProductUnit($unit)
                            ->setPrice(Price::create('1.00', 'USD'))
                    )
            );
        }
        $manager->persist($rfq);
        $this->setReference($reference, $rfq);
    }

    /**
     * @param Product|null $product the order's line item, or null for an order without one
     * @param Order|null   $parent  makes the order a sub-order; its line item then belongs to the parent too
     */
    private function createOrder(
        ObjectManager $manager,
        Customer $customer,
        User $owner,
        string $status,
        string $createdAt, // month, day and time in the previous year, UTC
        ?Product $product,
        ProductUnit $unit,
        ?Order $parent = null
    ): Order {
        $order = new Order();
        $order->setIdentifier(uniqid('smart-brief-purchase-', true));
        $order->setOwner($owner);
        $order->setOrganization($customer->getOrganization());
        $order->setCustomer($customer);
        $order->setCurrency('USD');
        $order->setTotalObject(MultiCurrency::create('10.0000', 'USD', '10.0000'));
        $order->setCreatedAt(new \DateTime(self::lastYear($createdAt), new \DateTimeZone('UTC')));
        $order->setInternalStatus($this->getStatus($manager, $status));
        if (null !== $parent) {
            // set before the line item, so Order::addLineItem() adds it to the parent as well
            $parent->addSubOrder($order);
        }
        if (null !== $product) {
            $order->addLineItem(
                (new OrderLineItem())
                    ->setProduct($product)
                    ->setProductSku($product->getSku())
                    ->setQuantity(1)
                    ->setProductUnit($unit)
                    ->setPrice(Price::create('10.00', 'USD'))
            );
        }
        $manager->persist($order);

        return $order;
    }

    private function getStatus(ObjectManager $manager, string $status): EnumOption
    {
        $option = $manager->getRepository(EnumOption::class)
            ->find(ExtendHelper::buildEnumOptionId(Order::INTERNAL_STATUS_CODE, $status));
        if (null === $option) {
            throw new \LogicException(sprintf('The order status "%s" does not exist.', $status));
        }

        return $option;
    }
}
