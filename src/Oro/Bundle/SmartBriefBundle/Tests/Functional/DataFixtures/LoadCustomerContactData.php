<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\ActivityListBundle\Entity\ActivityList;
use Oro\Bundle\ActivityListBundle\Entity\ActivityOwner;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\NoteBundle\Entity\Note;
use Oro\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadOrderUsers;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\ContactActivityClasses;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Activity-list records for the last contact tests. The source reads `ActivityList` (date, class, targets, owners), so
 * the records are created directly; their dates are set in the database after the flush. A scenario activity with a
 * contact time also gets a real call (its `callDateTime`) or conversation (a last message at that time, the
 * conversation itself created earlier), inserted without the entities' listeners; the other records point to ids
 * no call or conversation has. Every
 * {@see SCENARIOS} customer has one customer user ("Amanda Cole") and one child customer with its own user. The
 * subjects carry a person's name on purpose: no fact may contain it.
 *
 * ACL_CUSTOMER, all on its customer user: a call of the test user (2026-09-25), an email of the test user
 * (2026-10-01), an email without owner (2026-10-02) and a newer email of another user (2026-10-04).
 *
 * The PERFORMANCE_* customers each have a customer user and no activities; the performance test adds them.
 */
class LoadCustomerContactData extends AbstractFixture implements DependentFixtureInterface
{
    /** A non-contact activity of the optional oro/crm-task-bundle, named without importing it */
    public const string TASK_CLASS = 'Oro\\Bundle\\TaskBundle\\Entity\\Task';

    private const string CALL = ContactActivityClasses::CALL_CLASS;
    private const string CONVERSATION = ContactActivityClasses::CONVERSATION_CLASS;

    public const string SCENARIO_PREFIX = 'smart_brief.contact_customer.scenario.';
    public const string ACL_CUSTOMER = 'smart_brief.contact_customer.acl';
    public const string PERFORMANCE_CUSTOMER = 'smart_brief.contact_customer.performance';
    public const string PERFORMANCE_NOISE_CUSTOMER = 'smart_brief.contact_customer.performance.noise';
    public const string PERFORMANCE_OLD_CONTACT_CUSTOMER = 'smart_brief.contact_customer.performance.old';
    public const string PERFORMANCE_NO_CONTACT_CUSTOMER = 'smart_brief.contact_customer.performance.none';
    public const string LATE_EVENING_SCENARIO = 'email late in the UTC evening';
    public const string SUBJECT = 'Call back Amanda Cole about the price list';

    public const string ON_CUSTOMER = 'customer';
    public const string ON_CUSTOMER_USER = 'customer user';
    public const string ON_CHILD_CUSTOMER_USER = 'child customer user';
    public const string BY_USER = 'user';
    public const string BY_OTHER_USER = 'other user';
    public const string WITHOUT_OWNER = 'none';

    /**
     * Scenario => [activities as [class, target, updatedAt in UTC, owner, optional contact time in UTC], expected
     * [channel, UTC date] or null].
     * Activities are created in the listed order, so a later one has the higher id.
     *
     * @var array<string, array{
     *     0: array<int, array{0: string, 1: string, 2: string, 3: string, 4?: string}>,
     *     1: ?array
     * }>
     */
    public const array SCENARIOS = [
        'email on a customer user' => [
            [[Email::class, self::ON_CUSTOMER_USER, '2026-10-04 09:00:00', self::BY_USER]],
            ['email', '2026-10-04'],
        ],
        'call on a customer user newer than an email on the customer' => [
            [
                [Email::class, self::ON_CUSTOMER, '2026-10-01 10:00:00', self::BY_USER],
                [self::CALL, self::ON_CUSTOMER_USER, '2026-10-04 10:00:00', self::BY_USER],
            ],
            ['call', '2026-10-04'],
        ],
        'conversation on a customer user' => [
            [[self::CONVERSATION, self::ON_CUSTOMER_USER, '2026-10-03 10:00:00', self::BY_USER]],
            ['conversation', '2026-10-03'],
        ],
        'only a note and a task' => [
            [
                [Note::class, self::ON_CUSTOMER, '2026-10-04 10:00:00', self::BY_USER],
                [self::TASK_CLASS, self::ON_CUSTOMER, '2026-10-05 10:00:00', self::BY_USER],
            ],
            null,
        ],
        'a newer note does not hide an older email' => [
            [
                [Email::class, self::ON_CUSTOMER, '2026-09-01 10:00:00', self::BY_USER],
                [Note::class, self::ON_CUSTOMER, '2026-10-06 10:00:00', self::BY_USER],
            ],
            ['email', '2026-09-01'],
        ],
        'email only on a child customer user' => [
            [[Email::class, self::ON_CHILD_CUSTOMER_USER, '2026-10-04 10:00:00', self::BY_USER]],
            null,
        ],
        'automated email without an owner' => [
            [[Email::class, self::ON_CUSTOMER_USER, '2026-10-05 08:00:00', self::WITHOUT_OWNER]],
            ['email', '2026-10-05'],
        ],
        self::LATE_EVENING_SCENARIO => [
            [[Email::class, self::ON_CUSTOMER_USER, '2026-10-04 22:30:00', self::BY_USER]],
            ['email', '2026-10-04'],
        ],
        'an edited call counts at its call time' => [
            [
                [Email::class, self::ON_CUSTOMER, '2026-10-03 10:00:00', self::BY_USER],
                [self::CALL, self::ON_CUSTOMER_USER, '2026-10-06 10:00:00', self::BY_USER, '2026-10-01 09:00:00'],
            ],
            ['email', '2026-10-03'],
        ],
        'a call counts at its call time' => [
            [[self::CALL, self::ON_CUSTOMER_USER, '2026-10-06 10:00:00', self::BY_USER, '2026-10-05 09:00:00']],
            ['call', '2026-10-05'],
        ],
        'a planned call is not a contact yet' => [
            [
                [Email::class, self::ON_CUSTOMER, '2026-10-02 10:00:00', self::BY_USER],
                [self::CALL, self::ON_CUSTOMER_USER, '2026-10-02 11:00:00', self::BY_USER, '2099-01-01 10:00:00'],
            ],
            ['email', '2026-10-02'],
        ],
        'a conversation counts at its last message' => [
            [
                [Email::class, self::ON_CUSTOMER, '2026-10-04 10:00:00', self::BY_USER],
                [
                    self::CONVERSATION,
                    self::ON_CUSTOMER_USER,
                    '2026-10-06 10:00:00',
                    self::BY_USER,
                    '2026-10-03 08:00:00',
                ],
            ],
            ['email', '2026-10-04'],
        ],
        'a conversation with a recent message' => [
            [
                [
                    self::CONVERSATION,
                    self::ON_CUSTOMER_USER,
                    '2026-10-06 10:00:00',
                    self::BY_USER,
                    '2026-10-05 08:00:00',
                ],
            ],
            ['conversation', '2026-10-05'],
        ],
        'same time: the higher id wins' => [
            [
                [Email::class, self::ON_CUSTOMER_USER, '2026-10-02 10:00:00', self::BY_USER],
                [self::CALL, self::ON_CUSTOMER_USER, '2026-10-02 10:00:00', self::BY_USER],
            ],
            ['call', '2026-10-02'],
        ],
    ];

    /** @var array<int, array{0: ActivityList, 1: string}> [activity list, updatedAt] set after the flush */
    private array $dates = [];

    /** @var array<int, array{0: ActivityList, 1: string, 2: string}> [activity list, class, contact time] */
    private array $contacts = [];

    /** Far above any real call or conversation id, so the plain records join none. */
    private int $relatedActivityId = 1000000000;

    /**
     * The scenarios need the optional call, conversation and task packages.
     */
    public static function isApplicable(): bool
    {
        return class_exists(self::CALL)
            && class_exists(self::CONVERSATION)
            && class_exists(self::TASK_CLASS);
    }

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
        $owners = [
            self::BY_USER => $this->getReference(LoadUser::USER),
            self::BY_OTHER_USER => $this->getReference(LoadOrderUsers::ORDER_USER_1),
            self::WITHOUT_OWNER => null,
        ];

        foreach (self::SCENARIOS as $scenario => [$activities]) {
            $targets = $this->createCustomerWithUsers($manager, self::SCENARIO_PREFIX . $scenario, $organization);
            foreach ($activities as $activity) {
                [$class, $target, $updatedAt, $owner] = $activity;
                $activityList = $this->createActivity(
                    $manager,
                    $class,
                    $targets[$target],
                    $updatedAt,
                    $owners[$owner],
                    $organization
                );
                if (isset($activity[4])) {
                    $this->contacts[] = [$activityList, $class, $activity[4]];
                }
            }
        }

        $acl = $this->createCustomerWithUsers($manager, self::ACL_CUSTOMER, $organization);
        $customerUser = $acl[self::ON_CUSTOMER_USER];
        $call = '2026-09-25 10:00:00';
        $this->createActivity($manager, self::CALL, $customerUser, $call, $owners[self::BY_USER], $organization);
        $own = '2026-10-01 10:00:00';
        $this->createActivity($manager, Email::class, $customerUser, $own, $owners[self::BY_USER], $organization);
        $ownerless = '2026-10-02 10:00:00';
        $this->createActivity($manager, Email::class, $customerUser, $ownerless, null, $organization);
        $other = '2026-10-04 10:00:00';
        $otherUser = $owners[self::BY_OTHER_USER];
        $this->createActivity($manager, Email::class, $customerUser, $other, $otherUser, $organization);

        foreach (
            [
                self::PERFORMANCE_CUSTOMER,
                self::PERFORMANCE_NOISE_CUSTOMER,
                self::PERFORMANCE_OLD_CONTACT_CUSTOMER,
                self::PERFORMANCE_NO_CONTACT_CUSTOMER,
            ] as $name
        ) {
            $performance = $this->createCustomerWithUsers($manager, $name, $organization);
            $this->setReference($name . '.user', $performance[self::ON_CUSTOMER_USER]);
        }

        $manager->flush();
        foreach ($this->dates as [$activityList, $updatedAt]) {
            $manager->getConnection()->executeStatement(
                'UPDATE oro_activity_list SET created_at = :date, updated_at = :date WHERE id = :id',
                ['date' => $updatedAt, 'id' => $activityList->getId()],
                ['date' => ParameterType::STRING, 'id' => ParameterType::INTEGER]
            );
        }
        foreach ($this->contacts as [$activityList, $class, $contactAt]) {
            $manager->getConnection()->executeStatement(
                'UPDATE oro_activity_list SET related_activity_id = :related WHERE id = :id',
                [
                    'related' => $this->insertContact($manager, $class, $contactAt, $organization),
                    'id' => $activityList->getId(),
                ]
            );
        }
    }

    private function insertContact(
        ObjectManager $manager,
        string $class,
        string $contactAt,
        Organization $organization
    ): int {
        $connection = $manager->getConnection();
        if (self::CALL === $class) {
            return (int)$connection->fetchOne(
                'INSERT INTO orocrm_call (subject, call_date_time, created_at, updated_at, organization_id)'
                . ' VALUES (:subject, :at, :at, :at, :organization) RETURNING id',
                ['subject' => self::SUBJECT, 'at' => $contactAt, 'organization' => $organization->getId()]
            );
        }

        $conversationId = (int)$connection->fetchOne(
            'INSERT INTO oro_conversation (messages_number, created_at, updated_at, organization_id)'
            . ' VALUES (1, :created, :created, :organization) RETURNING id',
            ['created' => '2026-09-01 08:00:00', 'organization' => $organization->getId()]
        );
        $messageId = (int)$connection->fetchOne(
            'INSERT INTO oro_conversation_message (index, conversation_id, created_at, updated_at)'
            . ' VALUES (1, :conversation, :at, :at) RETURNING id',
            ['conversation' => $conversationId, 'at' => $contactAt]
        );
        $connection->executeStatement(
            'UPDATE oro_conversation SET last_message_id = :message WHERE id = :id',
            ['message' => $messageId, 'id' => $conversationId]
        );

        return $conversationId;
    }

    /**
     * @return array<string, Customer|CustomerUser> target => entity
     */
    private function createCustomerWithUsers(ObjectManager $manager, string $name, Organization $organization): array
    {
        /** @var User $owner */
        $owner = $this->getReference(LoadUser::USER);
        $customer = $this->createCustomer($manager, $name, $organization, $owner);
        $child = $this->createCustomer($manager, $name . '.child', $organization, $owner);
        $child->setParent($customer);

        return [
            self::ON_CUSTOMER => $customer,
            self::ON_CUSTOMER_USER => $this->createCustomerUser($manager, $name, $customer, $owner),
            self::ON_CHILD_CUSTOMER_USER => $this->createCustomerUser($manager, $name . '.child', $child, $owner),
        ];
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

    private function createCustomerUser(
        ObjectManager $manager,
        string $name,
        Customer $customer,
        User $owner
    ): CustomerUser {
        $customerUser = (new CustomerUser())
            ->setFirstName('Amanda')
            ->setLastName('Cole')
            ->setEmail(md5($name) . '@example.com')
            ->setPassword('not-used')
            ->setCustomer($customer)
            ->setOwner($owner)
            ->setOrganization($customer->getOrganization());
        $manager->persist($customerUser);

        return $customerUser;
    }

    private function createActivity(
        ObjectManager $manager,
        string $class,
        Customer|CustomerUser $target,
        string $updatedAt,
        ?User $owner,
        Organization $organization
    ): ActivityList {
        $date = new \DateTime($updatedAt, new \DateTimeZone('UTC'));
        $activityList = (new ActivityList())
            ->setRelatedActivityClass($class)
            ->setRelatedActivityId(++$this->relatedActivityId)
            ->setVerb(ActivityList::VERB_CREATE)
            ->setSubject(self::SUBJECT)
            ->setDescription('Amanda Cole, +1 555 0100, 1 Main Street')
            ->setOrganization($organization)
            ->setCreatedAt($date)
            ->setUpdatedAt($date);
        $activityList->addActivityListTarget($target);
        if (null !== $owner) {
            $activityList->addActivityOwner(
                (new ActivityOwner())->setActivity($activityList)->setUser($owner)->setOrganization($organization)
            );
        }
        $manager->persist($activityList);
        $this->dates[] = [$activityList, $updatedAt];

        return $activityList;
    }
}
