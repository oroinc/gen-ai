<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
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
use Oro\Bundle\WebsiteBundle\Entity\Website;

/**
 * RFQs for the RFQ provider tests. Every RFQ carries
 * requester data that must never become a fact ({@see PERSON_DATA}), on the RFQ and on its customer user.
 *
 * - RFQ: customer "Acme Industrial", the default website; lines product-1 (100 l at USD 9.10, 200 l at USD 8.50),
 *   product-2 (10 bottles at USD 4.00) and product-1 again — 2 distinct products;
 * - GUEST_RFQ: as the storefront creates it — a guest customer user whose customer is named after the guest
 *   ("Amanda Cole"); product-1 and product-2;
 * - NO_WEBSITE_RFQ: customer, no website; product-1;
 * - INCOMPLETE_RFQ: customer; a line whose product was deleted and a product-3 item without quantity;
 * - ONE_LINE_RFQ and FIFTY_LINE_RFQ: customer; 1 and 50 lines, for the query-count test.
 */
class LoadRfqSummaryData extends AbstractFixture implements DependentFixtureInterface
{
    public const string CUSTOMER = 'smart_brief.rfq_summary.customer';
    public const string CUSTOMER_NAME = 'Acme Industrial';
    public const string RFQ = 'smart_brief.rfq_summary.rfq';
    public const string GUEST_RFQ = 'smart_brief.rfq_summary.guest';
    public const string NO_WEBSITE_RFQ = 'smart_brief.rfq_summary.no_website';
    public const string INCOMPLETE_RFQ = 'smart_brief.rfq_summary.incomplete';
    public const string ONE_LINE_RFQ = 'smart_brief.rfq_summary.one_line';
    public const string FIFTY_LINE_RFQ = 'smart_brief.rfq_summary.fifty_lines';

    /** requester data that no fact may contain */
    public const array PERSON_DATA = [
        'first_name' => 'Amanda',
        'last_name' => 'Cole',
        'email' => 'amanda.cole@example.com',
        'phone' => '+1 555 0100',
        'note' => 'Please call Amanda before noon',
        'po_number' => 'PO-77-SECRET',
        'comment' => 'Deliver to the back door',
    ];

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOrganization::class, LoadUser::class, LoadProductData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        /** @var Organization $organization */
        $organization = $this->getReference(LoadOrganization::ORGANIZATION);
        /** @var User $owner */
        $owner = $this->getReference(LoadUser::USER);
        $website = $manager->getRepository(Website::class)->getDefaultWebsite();
        if (null === $website) {
            // without it the "with website" and "no website" cases would silently be the same
            throw new \LogicException('The default website is missing.');
        }
        $customer = $this->createCustomer($manager, self::CUSTOMER_NAME, $organization, $owner);
        $this->setReference(self::CUSTOMER, $customer);
        $customerUser = $this->createCustomerUser($manager, 'registered', $customer, $owner, false);
        $guestCustomer = $this->createCustomer(
            $manager,
            self::PERSON_DATA['first_name'] . ' ' . self::PERSON_DATA['last_name'],
            $organization,
            $owner
        );
        $guestUser = $this->createCustomerUser($manager, 'guest', $guestCustomer, $owner, true);

        $product1 = $this->getProduct(LoadProductData::PRODUCT_1);
        $product2 = $this->getProduct(LoadProductData::PRODUCT_2);
        $liter = $this->getUnit(LoadProductUnits::LITER);
        $bottle = $this->getUnit(LoadProductUnits::BOTTLE);

        $rfq = $this->createRfq($manager, self::RFQ, $customerUser, $website, $organization, $owner);
        $this->addLine($rfq, $product1, [['100', $liter, '9.10'], ['200', $liter, '8.50']]);
        $this->addLine($rfq, $product2, [['10', $bottle, '4.00']]);
        $this->addLine($rfq, $product1, [['5', $liter, '9.50']]);

        $guest = $this->createRfq($manager, self::GUEST_RFQ, $guestUser, $website, $organization, $owner);
        $this->addLine($guest, $product1, [['100', $liter, '9.10']]);
        $this->addLine($guest, $product2, [['10', $bottle, '4.00']]);

        $noWebsite = $this->createRfq($manager, self::NO_WEBSITE_RFQ, $customerUser, null, $organization, $owner);
        $this->addLine($noWebsite, $product1, [['100', $liter, '9.10']]);

        $incomplete = $this->createRfq($manager, self::INCOMPLETE_RFQ, $customerUser, $website, $organization, $owner);
        $this->addLine($incomplete, null, [['1', $liter, '1.00']]);
        $this->addLine($incomplete, $this->getProduct(LoadProductData::PRODUCT_3), [[null, $liter, '2.00']]);

        $oneLine = $this->createRfq($manager, self::ONE_LINE_RFQ, $customerUser, $website, $organization, $owner);
        $this->addLine($oneLine, $product1, [['1', $liter, '1.00']]);
        $fiftyLines = $this->createRfq($manager, self::FIFTY_LINE_RFQ, $customerUser, $website, $organization, $owner);
        $products = array_map(
            fn (string $sku) => $this->getProduct($sku),
            [LoadProductData::PRODUCT_1, LoadProductData::PRODUCT_2, LoadProductData::PRODUCT_3]
        );
        for ($line = 0; $line < 50; $line++) {
            $this->addLine($fiftyLines, $products[$line % 3], [[(string)($line + 1), $liter, '1.00']]);
        }

        $manager->flush();
    }

    private function createCustomer(
        ObjectManager $manager,
        string $name,
        Organization $organization,
        User $owner
    ): Customer {
        $customer = (new Customer())->setName($name)->setOrganization($organization)->setOwner($owner);
        $manager->persist($customer);

        return $customer;
    }

    private function createCustomerUser(
        ObjectManager $manager,
        string $name,
        Customer $customer,
        User $owner,
        bool $guest
    ): CustomerUser {
        $customerUser = (new CustomerUser())
            ->setFirstName(self::PERSON_DATA['first_name'])
            ->setLastName(self::PERSON_DATA['last_name'])
            ->setEmail($name . '.' . self::PERSON_DATA['email'])
            ->setPassword('not-used')
            ->setIsGuest($guest)
            ->setCustomer($customer)
            ->setOwner($owner)
            ->setOrganization($customer->getOrganization());
        $manager->persist($customerUser);

        return $customerUser;
    }

    private function createRfq(
        ObjectManager $manager,
        string $reference,
        CustomerUser $customerUser,
        ?Website $website,
        Organization $organization,
        User $owner
    ): Request {
        $rfq = (new Request())
            ->setFirstName(self::PERSON_DATA['first_name'])
            ->setLastName(self::PERSON_DATA['last_name'])
            ->setEmail(self::PERSON_DATA['email'])
            ->setPhone(self::PERSON_DATA['phone'])
            ->setNote(self::PERSON_DATA['note'])
            ->setPoNumber(self::PERSON_DATA['po_number'])
            ->setCompany(self::CUSTOMER_NAME)
            ->setCustomerUser($customerUser)
            ->setCustomer($customerUser->getCustomer())
            ->setWebsite($website)
            ->setOrganization($organization)
            ->setOwner($owner);
        $manager->persist($rfq);
        $this->setReference($reference, $rfq);

        return $rfq;
    }

    /**
     * @param array<int, array{0: ?string, 1: ProductUnit, 2: string}> $items [quantity, unit, requested USD price]
     */
    private function addLine(Request $rfq, ?Product $product, array $items): void
    {
        $line = (new RequestProduct())
            ->setProduct($product)
            ->setProductSku($product?->getSku() ?? 'DELETED-SKU')
            ->setComment(self::PERSON_DATA['comment']);
        foreach ($items as [$quantity, $unit, $price]) {
            $line->addRequestProductItem(
                (new RequestProductItem())
                    ->setQuantity($quantity)
                    ->setProductUnit($unit)
                    ->setPrice(Price::create($price, 'USD'))
            );
        }
        $rfq->addRequestProduct($line);
    }

    private function getProduct(string $sku): Product
    {
        return $this->getReference($sku);
    }

    private function getUnit(string $reference): ProductUnit
    {
        return $this->getReference($reference);
    }
}
