<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CurrencyBundle\Entity\MultiCurrency;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;

/**
 * A newer order of LoadCustomerOrderData::CUSTOMER that belongs to another organization (the organization
 * boundary): a user with organization-level access must not see it.
 */
class LoadCrossOrganizationOrderData extends AbstractFixture implements DependentFixtureInterface
{
    public const string ORGANIZATION = 'smart_brief.organization.other';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadCustomerOrderData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $organization = (new Organization())->setName(self::ORGANIZATION)->setEnabled(true);
        $manager->persist($organization);
        $this->setReference(self::ORGANIZATION, $organization);

        $order = new Order();
        $order->setIdentifier('smart_brief.order.other_organization');
        $order->setOwner($this->getReference(LoadUser::USER));
        $order->setOrganization($organization);
        $order->setCustomer($this->getReference(LoadCustomerOrderData::CUSTOMER));
        $order->setCurrency('USD');
        $order->setTotalObject(MultiCurrency::create('9.0000', 'USD', '9.0000'));
        $order->setCreatedAt(new \DateTime('2026-10-05 10:00:00', new \DateTimeZone('UTC')));
        $manager->persist($order);

        $manager->flush();
    }
}
