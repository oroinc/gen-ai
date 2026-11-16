<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOption;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;

/**
 * A quote sent to LoadCustomerRfqData::ACL_CUSTOMER that belongs to another organization and expires before the
 * customer's own quotes (the organization boundary): a user with organization-level access must not count it.
 */
class LoadCrossOrganizationQuoteData extends AbstractFixture implements DependentFixtureInterface
{
    #[\Override]
    public function getDependencies(): array
    {
        return [LoadCustomerRfqData::class, LoadCrossOrganizationOrderData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $quote = (new Quote())
            ->setOwner($this->getReference(LoadUser::USER))
            ->setOrganization($this->getReference(LoadCrossOrganizationOrderData::ORGANIZATION))
            ->setCustomer($this->getReference(LoadCustomerRfqData::ACL_CUSTOMER))
            ->setValidUntil(new \DateTime('2099-10-01 10:00:00', new \DateTimeZone('UTC')));
        $manager->persist($quote);
        $manager->flush();

        // the quote workflow sets the default status on creation, so the status comes after the first flush
        $quote->setInternalStatus($manager->getRepository(EnumOption::class)->find(
            ExtendHelper::buildEnumOptionId(Quote::INTERNAL_STATUS_CODE, Quote::INTERNAL_STATUS_SENT_TO_CUSTOMER)
        ));
        $manager->flush();
    }
}
