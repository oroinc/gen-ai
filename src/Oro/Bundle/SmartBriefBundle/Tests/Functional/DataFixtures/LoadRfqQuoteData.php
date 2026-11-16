<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOption;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadOrderUsers;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Quotes for the RFQ quote tests. Customer "Northwind Supply" has, by QID:
 * - 41 sent_to_customer, for RFQ_TWO_LINKED, created 09-01;
 * - 45 accepted, for RFQ_TWO_LINKED, created 09-20, owned by another user (the linked quote; not counted);
 * - 42 sent_to_customer, for RFQ_SENT, created 09-10;
 * - 60 declined, for RFQ_SENT, created 09-30 (ended, never the linked quote);
 * - 43 draft, no RFQ, created 09-05, owned by another user;
 * - 50 sent_to_customer with the expired flag and 51 sent_to_customer valid until earlier today, both for
 *   RFQ_EXPIRED_ONLY (not live: not counted, never linked).
 * Customer "Contoso" has 99 sent_to_customer for RFQ_SENT, created 10-01 (another customer: ignored).
 * Customer "Fabrikam" has RFQ_NO_QUOTES and no quotes.
 * Customer "Tailspin" has 70 without status (removed in the database; live) for RFQ_NO_STATUS, and 71 and 72 in
 * draft for RFQ_TIE, both created 09-15 10:00 (72 has the higher id): 3 active quotes.
 * Active quotes of Northwind: 41, 42, 43 (3); with user-level quote access: 41, 42 (2).
 */
class LoadRfqQuoteData extends AbstractFixture implements DependentFixtureInterface
{
    public const string RFQ_TWO_LINKED = 'smart_brief.rfq_quote.rfq.two_linked';
    public const string RFQ_SENT = 'smart_brief.rfq_quote.rfq.sent';
    public const string RFQ_EXPIRED_ONLY = 'smart_brief.rfq_quote.rfq.expired_only';
    public const string RFQ_NO_QUOTES = 'smart_brief.rfq_quote.rfq.no_quotes';
    public const string RFQ_NO_STATUS = 'smart_brief.rfq_quote.rfq.no_status';
    public const string RFQ_TIE = 'smart_brief.rfq_quote.rfq.tie';

    /** marks a quote whose internal status is removed in the database after the flush */
    private const string NO_STATUS = '';

    /** @var array<int, array{0: Quote, 1: string, 2: string}> [quote, status, created_at] set after the flush */
    private array $pending = [];

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOrganization::class, LoadUser::class, LoadOrderUsers::class];
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

        $northwind = $this->createCustomerUser($manager, 'Northwind Supply', $organization, $user);
        $contoso = $this->createCustomerUser($manager, 'Contoso', $organization, $user);
        $fabrikam = $this->createCustomerUser($manager, 'Fabrikam', $organization, $user);

        $twoLinked = $this->createRfq($manager, self::RFQ_TWO_LINKED, $northwind, $user);
        $sent = $this->createRfq($manager, self::RFQ_SENT, $northwind, $user);
        $expiredOnly = $this->createRfq($manager, self::RFQ_EXPIRED_ONLY, $northwind, $user);
        $this->createRfq($manager, self::RFQ_NO_QUOTES, $fabrikam, $user);
        $tailspin = $this->createCustomerUser($manager, 'Tailspin', $organization, $user);
        $noStatus = $this->createRfq($manager, self::RFQ_NO_STATUS, $tailspin, $user);
        $tie = $this->createRfq($manager, self::RFQ_TIE, $tailspin, $user);

        $this->createQuote($manager, '41', $northwind, $user, 'sent_to_customer', '2026-09-01', $twoLinked);
        $this->createQuote($manager, '45', $northwind, $other, 'accepted', '2026-09-20', $twoLinked);
        $this->createQuote($manager, '42', $northwind, $user, 'sent_to_customer', '2026-09-10', $sent);
        $this->createQuote($manager, '60', $northwind, $user, 'declined', '2026-09-30', $sent);
        $this->createQuote($manager, '43', $northwind, $other, 'draft', '2026-09-05');
        $this->createQuote($manager, '50', $northwind, $user, 'sent_to_customer', '2026-09-25', $expiredOnly)
            ->setExpired(true);
        $earlierToday = max(
            new \DateTime('-1 hour', new \DateTimeZone('UTC')),
            new \DateTime('today 00:00:01', new \DateTimeZone('UTC'))
        );
        $this->createQuote($manager, '51', $northwind, $user, 'sent_to_customer', '2026-09-26', $expiredOnly)
            ->setValidUntil($earlierToday);
        $this->createQuote($manager, '99', $contoso, $user, 'sent_to_customer', '2026-10-01', $sent);
        $this->createQuote($manager, '70', $tailspin, $user, self::NO_STATUS, '2026-09-15', $noStatus);
        $this->createQuote($manager, '71', $tailspin, $user, 'draft', '2026-09-15', $tie);
        $this->createQuote($manager, '72', $tailspin, $user, 'draft', '2026-09-15', $tie);

        $manager->flush();
        // the quote workflow sets the default status on creation, so statuses come after the first flush
        foreach ($this->pending as [$quote, $status]) {
            if (self::NO_STATUS !== $status) {
                $quote->setInternalStatus($this->getStatus($manager, $status));
            }
        }
        $manager->flush();
        $connection = $manager->getConnection();
        foreach ($this->pending as [$quote, $status, $createdAt]) {
            $connection->executeStatement(
                'UPDATE oro_sale_quote SET created_at = :createdAt WHERE id = :id',
                ['createdAt' => $createdAt . ' 10:00:00', 'id' => $quote->getId()],
                ['createdAt' => ParameterType::STRING, 'id' => ParameterType::INTEGER]
            );
            if (self::NO_STATUS === $status) {
                $connection->executeStatement(
                    "UPDATE oro_sale_quote SET serialized_data = serialized_data - 'internal_status' WHERE id = :id",
                    ['id' => $quote->getId()],
                    ['id' => ParameterType::INTEGER]
                );
            }
        }
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
            ->setEmail(md5('smart_brief.rfq_quote.' . $name) . '@example.com')
            ->setPassword('not-used')
            ->setIsGuest(false)
            ->setCustomer($customer)
            ->setOwner($owner)
            ->setOrganization($organization);
        $manager->persist($customerUser);

        return $customerUser;
    }

    private function createRfq(
        ObjectManager $manager,
        string $reference,
        CustomerUser $customerUser,
        User $owner
    ): Request {
        $rfq = (new Request())
            ->setFirstName('Amanda')
            ->setLastName('Cole')
            ->setEmail('amanda.cole@example.com')
            ->setCompany($customerUser->getCustomer()->getName())
            ->setCustomerUser($customerUser)
            ->setCustomer($customerUser->getCustomer())
            ->setOrganization($customerUser->getOrganization())
            ->setOwner($owner);
        $manager->persist($rfq);
        $this->setReference($reference, $rfq);

        return $rfq;
    }

    private function createQuote(
        ObjectManager $manager,
        string $qid,
        CustomerUser $customerUser,
        User $owner,
        string $status,
        string $createdAt,
        ?Request $rfq = null
    ): Quote {
        $quote = (new Quote())
            ->setQid($qid)
            ->setOwner($owner)
            ->setOrganization($customerUser->getOrganization())
            ->setCustomer($customerUser->getCustomer())
            ->setRequest($rfq);
        $manager->persist($quote);
        $this->pending[] = [$quote, $status, $createdAt];

        return $quote;
    }

    private function getStatus(ObjectManager $manager, string $status): EnumOption
    {
        $option = $manager->getRepository(EnumOption::class)
            ->find(ExtendHelper::buildEnumOptionId(Quote::INTERNAL_STATUS_CODE, $status));
        if (null === $option) {
            throw new \LogicException(sprintf('The quote status "%s" does not exist.', $status));
        }

        return $option;
    }
}
