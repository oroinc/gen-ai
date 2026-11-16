<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\ParameterType;
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
use Oro\Bundle\PricingBundle\Entity\CombinedPriceList;
use Oro\Bundle\PricingBundle\Entity\CombinedPriceListToCustomer;
use Oro\Bundle\PricingBundle\Entity\CombinedProductPrice;
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
use Oro\Bundle\WebsiteBundle\Entity\Website;

/**
 * Price-comparison data for the RFQ price ranges. Customer "Woodgrove" has a combined price list on the
 * default website: product-1 per liter USD 9.00 from 1 and USD 8.40 from 100 (tiers), product-2 per liter USD 8.00.
 * Its closed orders: product-1 per liter at USD 8.90 (08-01, the last paid; owned by another user and created
 * first, so "latest" means the date, not the id) and USD 9.50 (07-01, the last paid a user with user-level order
 * access sees), product-3 per liter in USD only, product-4 per bottle only, product-8 only on a bundled-price line.
 * product-5 is made a kit.
 *
 * RFQ_PRICED items, in line order (list range -2.3 to +8.3 %, last paid range +2.2 %):
 * - A: product-1, 100 l at USD 9.10 — list 8.40 (+8.3 %), last paid 8.90 (+2.2 %);
 * - D: product-2, 50 l at USD 7.82 — list 8.00 (−2.3 %), never bought;
 * - B: product-3, 10 l at EUR 4.00 — no EUR list price, bought only in USD: excluded;
 * - E: product-4, 5 l at USD 5.00 — bought only per bottle: excluded;
 * - kit: product-5, 1 l at USD 9.00 — not compared;
 * - product-8, 2.5 l at USD 1.00 — bought only on a bundled-price line: excluded;
 * - incomplete, skipped entirely: product-6 without price, without quantity, whose unit was deleted, with a negative
 *   quantity, and a deleted product.
 * RFQ_PRICED_NO_WEBSITE has item A only and no website. RFQ_ONE_ITEM and RFQ_FIFTY_ITEMS are for the query count.
 */
class LoadRfqPriceData extends AbstractFixture implements DependentFixtureInterface
{
    public const string RFQ_PRICED = 'smart_brief.rfq_price.rfq';
    public const string RFQ_PRICED_NO_WEBSITE = 'smart_brief.rfq_price.rfq.no_website';
    public const string RFQ_ONE_ITEM = 'smart_brief.rfq_price.rfq.one_item';
    public const string RFQ_FIFTY_ITEMS = 'smart_brief.rfq_price.rfq.fifty_items';

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
        $website = $manager->getRepository(Website::class)->getDefaultWebsite();
        if (null === $website) {
            throw new \LogicException('The default website is missing.');
        }
        $customerUser = $this->createCustomerUser($manager, 'Woodgrove', $organization, $user);
        $customer = $customerUser->getCustomer();
        $product = fn (string $sku) => $this->getReference($sku);
        $liter = $this->getReference(LoadProductUnits::LITER);
        $bottle = $this->getReference(LoadProductUnits::BOTTLE);
        [$p1, $p2, $p3, $p4, $p5, $p6, $p8] = array_map($product, [
            LoadProductData::PRODUCT_1,
            LoadProductData::PRODUCT_2,
            LoadProductData::PRODUCT_3,
            LoadProductData::PRODUCT_4,
            LoadProductData::PRODUCT_5,
            LoadProductData::PRODUCT_6,
            LoadProductData::PRODUCT_8,
        ]);
        /** @var User $other */
        $other = $this->getReference(LoadOrderUsers::ORDER_USER_1);

        $this->createPriceList($manager, $customer, $website, [
            [$p1, $liter, 1, '9.00'],
            [$p1, $liter, 100, '8.40'],
            [$p2, $liter, 1, '8.00'],
        ]);

        $this->createOrder($manager, $customer, $other, '08-01 10:00:00', [[$p1, $liter, '8.90', 'USD']]);
        $this->createOrder($manager, $customer, $user, '07-01 10:00:00', [[$p1, $liter, '9.50', 'USD']]);
        $this->createOrder($manager, $customer, $user, '08-04 10:00:00', [[$p8, $liter, '2.50', 'USD']], true);
        $this->createOrder($manager, $customer, $user, '08-02 10:00:00', [[$p3, $liter, '3.50', 'USD']]);
        $this->createOrder($manager, $customer, $user, '08-03 10:00:00', [[$p4, $bottle, '6.00', 'USD']]);

        $this->createRfq($manager, self::RFQ_PRICED, $customerUser, $user, $website, [
            [$p1, ['100', $liter, '9.10', 'USD']],
            [$p2, ['50', $liter, '7.82', 'USD']],
            [$p3, ['10', $liter, '4.00', 'EUR']],
            [$p4, ['5', $liter, '5.00', 'USD']],
            [$p5, ['1', $liter, '9.00', 'USD']],
            [$p8, ['2.5', $liter, '1.00', 'USD']],
            [$p6, ['1', $liter, null, null]],
            [$p6, [null, $liter, '1.00', 'USD']],
            [$p6, ['1', null, '1.00', 'USD']],
            [$p6, ['-1', $liter, '1.00', 'USD']],
            [null, ['1', $liter, '1.00', 'USD']],
        ]);
        $this->createRfq($manager, self::RFQ_PRICED_NO_WEBSITE, $customerUser, $user, null, [
            [$p1, ['100', $liter, '9.10', 'USD']],
        ]);
        $this->createRfq($manager, self::RFQ_ONE_ITEM, $customerUser, $user, $website, [
            [$p1, ['100', $liter, '9.10', 'USD']],
        ]);
        $fifty = [];
        for ($i = 0; $i < 50; $i++) {
            $fifty[] = [[$p1, $p2, $p3][$i % 3], [(string)($i + 1), $liter, '9.10', 'USD']];
        }
        $this->createRfq($manager, self::RFQ_FIFTY_ITEMS, $customerUser, $user, $website, $fifty);

        $manager->flush();
        // a kit only for this data set (the comparison reads nothing but the product type); rolled back with the test
        $manager->getConnection()->executeStatement(
            "UPDATE oro_product SET type = 'kit' WHERE id = :id",
            ['id' => $p5->getId()],
            ['id' => ParameterType::INTEGER]
        );
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
            ->setEmail(md5('smart_brief.rfq_price.' . $name) . '@example.com')
            ->setPassword('not-used')
            ->setIsGuest(false)
            ->setCustomer($customer)
            ->setOwner($owner)
            ->setOrganization($organization);
        $manager->persist($customerUser);

        return $customerUser;
    }

    /**
     * @param array<int, array{0: Product, 1: ProductUnit, 2: int, 3: string}> $prices
     */
    private function createPriceList(ObjectManager $manager, Customer $customer, Website $website, array $prices): void
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $priceList = (new CombinedPriceList())
            ->setName(md5('smart_brief.rfq_price'))
            ->setCurrencies(['USD'])
            ->setEnabled(true)
            ->setPricesCalculated(true)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $manager->persist($priceList);
        $relation = new CombinedPriceListToCustomer();
        $relation->setCustomer($customer);
        $relation->setWebsite($website);
        $relation->setPriceList($priceList);
        $manager->persist($relation);
        foreach ($prices as [$product, $unit, $quantity, $value]) {
            $manager->persist(
                (new CombinedProductPrice())
                    ->setPriceList($priceList)
                    ->setProduct($product)
                    ->setUnit($unit)
                    ->setQuantity($quantity)
                    ->setPrice(Price::create($value, 'USD'))
            );
        }
    }

    /**
     * @param array<int, array{0: Product, 1: ProductUnit, 2: string, 3: string}> $lines
     * @param bool                                                              $bundled a bundled (total) line price
     */
    private function createOrder(
        ObjectManager $manager,
        Customer $customer,
        User $owner,
        string $createdAt, // month, day and time in the previous year, UTC
        array $lines,
        bool $bundled = false
    ): void {
        $order = new Order();
        $order->setIdentifier(uniqid('smart-brief-price-', true));
        $order->setOwner($owner);
        $order->setOrganization($customer->getOrganization());
        $order->setCustomer($customer);
        $order->setCurrency('USD');
        $order->setTotalObject(MultiCurrency::create('10.0000', 'USD', '10.0000'));
        $order->setCreatedAt(new \DateTime(self::lastYear($createdAt), new \DateTimeZone('UTC')));
        $order->setInternalStatus($this->getStatus($manager, 'closed'));
        foreach ($lines as [$product, $unit, $value, $currency]) {
            $order->addLineItem(
                (new OrderLineItem())
                    ->setProduct($product)
                    ->setProductSku($product->getSku())
                    ->setQuantity(1)
                    ->setProductUnit($unit)
                    ->setPrice(Price::create($value, $currency))
                    ->setPriceType($bundled ? OrderLineItem::PRICE_TYPE_BUNDLED : OrderLineItem::PRICE_TYPE_UNIT)
            );
        }
        $manager->persist($order);
    }

    /**
     * @param array<int, array{0: ?Product, 1: array{0: ?string, 1: ?ProductUnit, 2: ?string, 3: ?string}}> $lines
     */
    private function createRfq(
        ObjectManager $manager,
        string $reference,
        CustomerUser $customerUser,
        User $owner,
        ?Website $website,
        array $lines
    ): void {
        $rfq = (new Request())
            ->setFirstName('Amanda')
            ->setLastName('Cole')
            ->setEmail('amanda.cole@example.com')
            ->setCompany($customerUser->getCustomer()->getName())
            ->setCustomerUser($customerUser)
            ->setCustomer($customerUser->getCustomer())
            ->setWebsite($website)
            ->setOrganization($customerUser->getOrganization())
            ->setOwner($owner);
        foreach ($lines as [$product, [$quantity, $unit, $value, $currency]]) {
            // a deleted unit leaves its code and nulls the reference (the code column is required)
            $item = (new RequestProductItem())
                ->setQuantity($quantity)
                ->setProductUnit($unit)
                ->setProductUnitCode($unit?->getCode() ?? 'deleted_unit');
            if (null !== $value) {
                $item->setPrice(Price::create($value, $currency));
            }
            $rfq->addRequestProduct(
                (new RequestProduct())
                    ->setProduct($product)
                    ->setProductSku($product?->getSku() ?? 'DELETED-SKU')
                    ->addRequestProductItem($item)
            );
        }
        $manager->persist($rfq);
        $this->setReference($reference, $rfq);
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
