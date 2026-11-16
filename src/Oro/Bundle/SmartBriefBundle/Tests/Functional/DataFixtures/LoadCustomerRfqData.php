<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
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
 * RFQs and quotes for the open RFQ and awaiting quote tests.
 *
 * CUSTOMER, the combined case: open RFQs of 07-01 without a quote (the oldest), 07-15 with a draft quote (live, so
 * not counted), 08-01 whose only quote is declined, 08-10 whose quote has the expired flag and 08-20 whose
 * quote's validUntil is in the past (all counted); a processed RFQ and an open RFQ of the child customer are not
 * counted. CUSTOMER also has quotes sent to the customer: valid until 2099-12-31, valid until 2099-06-30 (the
 * nearest expiry) and without validUntil; an expired one and one in status open are not awaiting the customer.
 *
 * Every {@see SCENARIOS} customer has exactly one RFQ, so each live/non-live rule is proven on its own and two
 * wrong rules cannot cancel each other out.
 *
 * Every {@see AWAITING_SCENARIOS} customer holds the quotes of one awaiting-quote rule.
 *
 * ACL_CUSTOMER, for the record-level ACL tests: an open RFQ of the test user without a quote (06-01), an open RFQ
 * of another user without a quote (05-01), an open RFQ of the test user whose only quote is a live draft owned
 * by another user (06-10), and quotes sent to the customer by the test user (valid until 2099-10-20) and by
 * another user (valid until 2099-10-10).
 */
class LoadCustomerRfqData extends AbstractFixture implements DependentFixtureInterface
{
    public const string CUSTOMER = 'smart_brief.rfq_customer';
    public const string CHILD_CUSTOMER = 'smart_brief.rfq_customer.child';
    public const string CUSTOMER_WITHOUT_RFQS = 'smart_brief.rfq_customer.none';
    public const string ACL_CUSTOMER = 'smart_brief.rfq_customer.acl';
    public const string SCENARIO_PREFIX = 'smart_brief.rfq_customer.scenario.';
    public const string AWAITING_PREFIX = 'smart_brief.quote_customer.scenario.';

    /** up to an hour after now, but still today in UTC, so the scenario tests "later today" at any hour */
    public const string LATER_TODAY = 'later today';
    /** up to an hour before now, but still today in UTC, so the scenario tests "earlier today" at any hour */
    public const string EARLIER_TODAY = 'earlier today';
    public const string LATER_TODAY_QUOTE = 'smart_brief.quote.later_today';

    /** marks a quote whose internal status is removed in the database after the flush */
    public const string NO_STATUS = '';

    /**
     * Scenario => [RFQ internal status, quotes as [internal status, expired flag, validUntil in UTC, optional
     * "belongs to another customer"], expected count]; the RFQ is created on 2026-09-15 10:00 UTC. validUntil is any
     * `\DateTime` string, so it may be relative to now.
     *
     * @var array<string, array{0: string, 1: array<int, array{0: string, 1: bool, 2: ?string, 3?: bool}>, 2: int}>
     */
    public const array SCENARIOS = [
        'no quote' => ['open', [], 1],
        'draft quote is live' => ['open', [['draft', false, null]], 0],
        'quote without status is live' => ['open', [[self::NO_STATUS, false, null]], 0],
        'sent quote valid until a future date is live' => [
            'open',
            [['sent_to_customer', false, '2099-01-01 10:00:00']],
            0,
        ],
        'sent quote without validUntil is live' => ['open', [['sent_to_customer', false, null]], 0],
        'declined quote' => ['open', [['declined', false, null]], 1],
        'deleted quote' => ['open', [['deleted', false, null]], 1],
        'cancelled quote' => ['open', [['cancelled', false, null]], 1],
        'expired status quote' => ['open', [['expired', false, null]], 1],
        'template quote' => ['open', [['template', false, null]], 1],
        'quote with the expired flag' => ['open', [['sent_to_customer', true, null]], 1],
        'quote valid until a past date' => ['open', [['sent_to_customer', false, '2020-01-01 10:00:00']], 1],
        'live quote moved to another customer' => ['open', [['draft', false, null, true]], 1],
        'one ended and one live quote' => ['open', [['declined', false, null], ['draft', false, null]], 0],
        'processed RFQ without a quote' => ['processed', [], 0],
    ];

    /**
     * Awaiting-quote scenario => [quotes without an RFQ as [internal status, expired flag, validUntil in
     * UTC or {@see LATER_TODAY}/{@see EARLIER_TODAY}], expected count, expected nearest validUntil (UTC date; `today`
     * is the date stored for the {@see LATER_TODAY_QUOTE} quote)].
     *
     * @var array<string, array{0: array<int, array{0: string, 1: bool, 2: ?string}>, 1: int, 2: ?string}>
     */
    public const array AWAITING_SCENARIOS = [
        'sent quote valid until later today' => [[['sent_to_customer', false, self::LATER_TODAY]], 1, 'today'],
        'sent quote that expired earlier today' => [[['sent_to_customer', false, self::EARLIER_TODAY]], 0, null],
        'sent quote with the expired flag' => [[['sent_to_customer', true, '2099-10-16 10:00:00']], 0, null],
        'draft, accepted and declined quotes' => [
            [['draft', false, null], ['accepted', false, null], ['declined', false, null]],
            0,
            null,
        ],
        'two sent quotes without validUntil' => [
            [['sent_to_customer', false, null], ['sent_to_customer', false, null]],
            2,
            null,
        ],
        'nearest of two sent quotes' => [
            [['sent_to_customer', false, '2099-10-16 10:00:00'], ['sent_to_customer', false, '2099-10-10 10:00:00']],
            2,
            '2099-10-10',
        ],
    ];

    /** @var array<string, array{0: Request, 1: string}> RFQ reference => [RFQ, created_at] set after the flush */
    private array $createdAt = [];

    /** @var Quote[] quotes whose internal status is removed after the flush */
    private array $statusless = [];

    /** @var array<int, array{0: Request|Quote, 1: string, 2: string}> [record, enum code, internal status] */
    private array $statuses = [];

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
        /** @var User $otherUser */
        $otherUser = $this->getReference(LoadOrderUsers::ORDER_USER_1);

        $this->loadCombinedCase($manager, $organization, $user);
        $this->loadScenarios($manager, $organization, $user);
        $this->loadAwaitingScenarios($manager, $organization, $user);
        $this->loadAclCase($manager, $organization, $user, $otherUser);

        $manager->flush();
        // the RFQ and quote workflows start on creation and set their default status, so statuses come after it
        foreach ($this->statuses as [$record, $code, $status]) {
            $record->setInternalStatus($this->getStatus($manager, $code, $status));
        }
        $manager->flush();
        $connection = $manager->getConnection();
        foreach ($this->createdAt as [$rfq, $createdAt]) {
            $connection->executeStatement(
                'UPDATE oro_rfp_request SET created_at = :createdAt WHERE id = :id',
                ['createdAt' => $createdAt, 'id' => $rfq->getId()],
                ['createdAt' => ParameterType::STRING, 'id' => ParameterType::INTEGER]
            );
        }
        foreach ($this->statusless as $quote) {
            $connection->executeStatement(
                "UPDATE oro_sale_quote SET serialized_data = serialized_data - 'internal_status' WHERE id = :id",
                ['id' => $quote->getId()],
                ['id' => ParameterType::INTEGER]
            );
        }
    }

    private function loadCombinedCase(ObjectManager $manager, Organization $organization, User $user): void
    {
        $customer = $this->createCustomer($manager, self::CUSTOMER, $organization, $user);
        $child = $this->createCustomer($manager, self::CHILD_CUSTOMER, $organization, $user);
        $child->setParent($customer);
        $this->createCustomer($manager, self::CUSTOMER_WITHOUT_RFQS, $organization, $user);

        $rfqs = [];
        foreach (
            [
            'no_quote' => [$customer, 'open', '2026-07-01 10:00:00'],
            'draft_quote' => [$customer, 'open', '2026-07-15 10:00:00'],
            'declined_quote' => [$customer, 'open', '2026-08-01 10:00:00'],
            'expired_flag_quote' => [$customer, 'open', '2026-08-10 10:00:00'],
            'past_valid_until_quote' => [$customer, 'open', '2026-08-20 10:00:00'],
            'processed' => [$customer, 'processed', '2026-09-01 10:00:00'],
            'child' => [$child, 'open', '2026-09-05 10:00:00'],
            ] as $name => [$rfqCustomer, $status, $createdAt]
        ) {
            $rfqs[$name] = $this->createRfq($manager, $name, $rfqCustomer, $user, $status, $createdAt);
        }

        $this->createQuote($manager, $customer, $user, 'draft', $rfqs['draft_quote']);
        $this->createQuote($manager, $customer, $user, 'declined', $rfqs['declined_quote']);
        $this->createQuote($manager, $customer, $user, 'sent_to_customer', $rfqs['expired_flag_quote'])
            ->setExpired(true);
        $this->createQuote($manager, $customer, $user, 'sent_to_customer', $rfqs['past_valid_until_quote'])
            ->setValidUntil($this->utc('2020-01-01 00:00:00'));
        $this->createQuote($manager, $customer, $user, 'sent_to_customer')
            ->setValidUntil($this->utc('2099-12-31 10:00:00'));
        $this->createQuote($manager, $customer, $user, 'sent_to_customer')
            ->setValidUntil($this->utc('2099-06-30 10:00:00'));
        $this->createQuote($manager, $customer, $user, 'sent_to_customer');
        $this->createQuote($manager, $customer, $user, 'expired');
        $this->createQuote($manager, $customer, $user, 'open');
        $this->createQuote($manager, $child, $user, 'sent_to_customer');
    }

    private function loadScenarios(ObjectManager $manager, Organization $organization, User $user): void
    {
        $otherCustomer = $this->createCustomer($manager, self::SCENARIO_PREFIX . 'other', $organization, $user);
        foreach (self::SCENARIOS as $scenario => [$rfqStatus, $quotes]) {
            $customer = $this->createCustomer($manager, self::SCENARIO_PREFIX . $scenario, $organization, $user);
            $rfq = $this->createRfq($manager, $scenario, $customer, $user, $rfqStatus, '2026-09-15 10:00:00');
            foreach ($quotes as $quoteData) {
                [$quoteStatus, $expired, $validUntil] = $quoteData;
                $quote = $this->createQuote(
                    $manager,
                    ($quoteData[3] ?? false) ? $otherCustomer : $customer,
                    $user,
                    self::NO_STATUS === $quoteStatus ? 'draft' : $quoteStatus,
                    $rfq
                );
                $quote->setExpired($expired);
                if (null !== $validUntil) {
                    $quote->setValidUntil($this->utc($validUntil));
                }
                if (self::NO_STATUS === $quoteStatus) {
                    $this->statusless[] = $quote;
                }
            }
        }
    }

    private function loadAwaitingScenarios(ObjectManager $manager, Organization $organization, User $user): void
    {
        foreach (self::AWAITING_SCENARIOS as $scenario => [$quotes]) {
            $customer = $this->createCustomer($manager, self::AWAITING_PREFIX . $scenario, $organization, $user);
            foreach ($quotes as [$status, $expired, $validUntil]) {
                $quote = $this->createQuote($manager, $customer, $user, $status)->setExpired($expired);
                if (null !== $validUntil) {
                    $quote->setValidUntil($this->resolveValidUntil($validUntil));
                }
                if (self::LATER_TODAY === $validUntil) {
                    $this->setReference(self::LATER_TODAY_QUOTE, $quote);
                }
            }
        }
    }

    private function resolveValidUntil(string $validUntil): \DateTime
    {
        $now = $this->utc('now');

        return match ($validUntil) {
            self::LATER_TODAY => min($now->modify('+1 hour'), $this->utc('today 23:59:59')),
            self::EARLIER_TODAY => max($now->modify('-1 hour'), $this->utc('today 00:00:01')),
            default => $this->utc($validUntil),
        };
    }

    private function loadAclCase(ObjectManager $manager, Organization $organization, User $user, User $other): void
    {
        $customer = $this->createCustomer($manager, self::ACL_CUSTOMER, $organization, $user);
        $this->createRfq($manager, 'acl.own', $customer, $user, 'open', '2026-06-01 10:00:00');
        $this->createRfq($manager, 'acl.other_users', $customer, $other, 'open', '2026-05-01 10:00:00');
        $quoted = $this->createRfq($manager, 'acl.quoted_by_other', $customer, $user, 'open', '2026-06-10 10:00:00');
        $this->createQuote($manager, $customer, $other, 'draft', $quoted);
        // Quotes sent to the customer by the test user (nearest 10-20) and by another user (10-10)
        $this->createQuote($manager, $customer, $user, 'sent_to_customer')
            ->setValidUntil($this->utc('2099-10-20 10:00:00'));
        $this->createQuote($manager, $customer, $other, 'sent_to_customer')
            ->setValidUntil($this->utc('2099-10-10 10:00:00'));
    }

    private function createCustomer(
        ObjectManager $manager,
        string $name,
        Organization $organization,
        User $owner
    ): Customer {
        $customer = (new Customer())->setName($name)->setOrganization($organization)->setOwner($owner);
        $manager->persist($customer);
        $this->setReference($name, $customer);

        return $customer;
    }

    private function createRfq(
        ObjectManager $manager,
        string $name,
        Customer $customer,
        User $owner,
        string $status,
        string $createdAt
    ): Request {
        $rfq = (new Request())
            ->setFirstName('Ai')
            ->setLastName('Summary')
            ->setEmail('ai.summary@example.com')
            ->setCompany($name)
            ->setOwner($owner)
            ->setOrganization($customer->getOrganization())
            ->setCustomer($customer);
        $manager->persist($rfq);
        $this->statuses[] = [$rfq, Request::INTERNAL_STATUS_CODE, $status];
        $reference = 'smart_brief.rfq.' . $name;
        $this->setReference($reference, $rfq);
        $this->createdAt[$reference] = [$rfq, $createdAt];

        return $rfq;
    }

    private function createQuote(
        ObjectManager $manager,
        Customer $customer,
        User $owner,
        string $status,
        ?Request $rfq = null
    ): Quote {
        $quote = (new Quote())
            ->setOwner($owner)
            ->setOrganization($customer->getOrganization())
            ->setCustomer($customer)
            ->setRequest($rfq);
        $manager->persist($quote);
        $this->statuses[] = [$quote, Quote::INTERNAL_STATUS_CODE, $status];

        return $quote;
    }

    private function getStatus(ObjectManager $manager, string $code, string $status): EnumOption
    {
        $option = $manager->getRepository(EnumOption::class)->find(ExtendHelper::buildEnumOptionId($code, $status));
        if (null === $option) {
            // a mistyped status would otherwise become a record without status and prove nothing
            throw new \LogicException(sprintf('The "%s" option of "%s" does not exist.', $status, $code));
        }

        return $option;
    }

    private function utc(string $dateTime): \DateTime
    {
        return new \DateTime($dateTime, new \DateTimeZone('UTC'));
    }
}
