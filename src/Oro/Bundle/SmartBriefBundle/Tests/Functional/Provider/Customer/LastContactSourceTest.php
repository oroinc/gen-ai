<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Oro\Bundle\ActivityListBundle\Entity\ActivityList;
use Oro\Bundle\ActivityListBundle\Tools\ActivityListEntityConfigDumperExtension;
use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastContactSource;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerContactData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\FactValuesTrait;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * The newest email, call or conversation
 * on the customer or its customer users, one rule per scenario customer, no person data in the facts, two queries
 * (one per target type) whatever the number of activities, and (with ORO_SMART_BRIEF_PERFORMANCE=1) the p95 limit.
 * Which classes and target types are searched follows the user's permissions; {@see LastContactAclTest} covers it.
 */
final class LastContactSourceTest extends WebTestCase
{
    use FactValuesTrait;
    use QueryCountTrait;
    use ConfigManagerAwareTestTrait;

    private const string PERFORMANCE_ENV = 'ORO_SMART_BRIEF_PERFORMANCE';
    private const string GENERATED_SUBJECT = 'smart_brief.performance';

    #[\Override]
    protected function setUp(): void
    {
        if (!LoadCustomerContactData::isApplicable()) {
            self::markTestSkipped('The call, conversation and task packages are not installed.');
        }
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerContactData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
        // conversations count as contact only while their feature is on
        $this->setConversationFeature(true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setConversationFeature(false);
        parent::tearDown();
    }

    public function testAConversationIsNoContactWhileItsFeatureIsOff(): void
    {
        $this->setConversationFeature(false);

        $facts = $this->getFacts(
            $this->getReference(LoadCustomerContactData::SCENARIO_PREFIX . 'conversation on a customer user')
        );

        self::assertNull($facts->get('last_contact_date'));
        self::assertNull($facts->get('last_contact_channel'));
    }

    private function setConversationFeature(bool $enabled): void
    {
        $configManager = self::getConfigManager();
        $configManager->set('oro_conversation.enable_conversation', $enabled);
        $configManager->flush();
        self::getContainer()->get('oro_featuretoggle.checker.feature_checker')->resetCache();
    }

    /**
     * @dataProvider scenarioDataProvider
     */
    public function testEachRuleOnItsOwn(string $scenario, ?array $expected): void
    {
        $facts = $this->getFacts($this->getReference(LoadCustomerContactData::SCENARIO_PREFIX . $scenario));

        $values = [];
        foreach ($facts as $fact) {
            if (str_starts_with($fact->key, 'last_contact_')) {
                $values[$fact->key] = $fact->value;
            }
        }
        self::assertSame(
            null === $expected ? [] : ['last_contact_date' => $expected[1], 'last_contact_channel' => $expected[0]],
            $values,
            'Date and channel only, no days-since fact.'
        );
    }

    public static function scenarioDataProvider(): array
    {
        $data = [];
        foreach (LoadCustomerContactData::SCENARIOS as $scenario => [, $expected]) {
            $data[$scenario] = [$scenario, $expected];
        }

        return $data;
    }

    public function testNoPersonDataInAnyFact(): void
    {
        // the facts allowed to reach the AI provider
        $allowedKeys = [
            CustomerSummaryFactProvider::COMPANY_NAME,
            CustomerSummaryFactProvider::LAST_ORDER_DATE,
            CustomerSummaryFactProvider::LAST_ORDER_TOTAL,
            CustomerSummaryFactProvider::OPEN_RFQ_COUNT,
            CustomerSummaryFactProvider::OLDEST_OPEN_RFQ_DATE,
            CustomerSummaryFactProvider::AWAITING_QUOTE_COUNT,
            CustomerSummaryFactProvider::NEAREST_QUOTE_EXPIRY_DATE,
            CustomerSummaryFactProvider::LAST_CONTACT_DATE,
            CustomerSummaryFactProvider::LAST_CONTACT_CHANNEL,
        ];
        foreach (array_keys(LoadCustomerContactData::SCENARIOS) as $scenario) {
            $facts = $this->getFacts($this->getReference(LoadCustomerContactData::SCENARIO_PREFIX . $scenario));
            foreach ($facts as $fact) {
                self::assertContains($fact->key, $allowedKeys, $scenario);
                if (!\is_scalar($fact->value)) {
                    continue;
                }
                foreach (['Amanda', 'Cole', '555', 'Main Street', LoadCustomerContactData::SUBJECT] as $personData) {
                    self::assertStringNotContainsString($personData, (string)$fact->value, $scenario);
                }
            }
        }
    }

    public function testTwoQueriesOnTenThousandActivities(): void
    {
        /** @var Customer $customer */
        $customer = $this->getReference(LoadCustomerContactData::PERFORMANCE_CUSTOMER);
        $this->insertEmailActivities(
            $this->getReference(LoadCustomerContactData::PERFORMANCE_CUSTOMER . '.user'),
            10000,
            '2020-01-01 00:00:00'
        );
        $source = $this->getSource();
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $customer);

        $facts = [];
        self::assertSame(2, $this->countQueries(
            ActivityList::class,
            function () use ($source, $customer, &$facts) {
                $facts = $this->getFactValues(static fn (FactSet $set) => $source->addFacts($set, $customer));
            }
        ));
        // the newest of the generated activities: 2020-01-01 00:00 plus 10 000 minutes
        self::assertSame(
            [
                'last_contact_date' => $this->toUserDate(
                    new \DateTimeImmutable('2020-01-07 22:40:00', new \DateTimeZone('UTC'))
                ),
                'last_contact_channel' => 'email',
            ],
            $facts
        );
    }

    /**
     * 10 000 activities on the subject's customer user plus 50 000 newer activities on another
     * customer's user (the shape that tempts the planner into a backwards updated_at scan), statistics refreshed;
     * p95 within 150 ms for the big customer, a customer with one old contact and a customer without any contact.
     * Timing is environment-dependent, so it runs only with ORO_SMART_BRIEF_PERFORMANCE=1.
     *
     * @group performance
     */
    public function testPerformanceFixture(): void
    {
        if (!getenv(self::PERFORMANCE_ENV)) {
            self::markTestSkipped(sprintf('Set %s=1 to run the performance fixture.', self::PERFORMANCE_ENV));
        }
        foreach (
            [
                [LoadCustomerContactData::PERFORMANCE_CUSTOMER, 10000, '2020-01-01'],
                [LoadCustomerContactData::PERFORMANCE_NOISE_CUSTOMER, 50000, '2025-01-01'],
                [LoadCustomerContactData::PERFORMANCE_OLD_CONTACT_CUSTOMER, 1, '2019-01-01'],
            ] as [$reference, $count, $start]
        ) {
            $this->insertEmailActivities($this->getReference($reference . '.user'), $count, $start);
        }
        $this->getConnection()->executeStatement('ANALYZE oro_activity_list');

        $source = $this->getSource();
        foreach (
            [
                LoadCustomerContactData::PERFORMANCE_CUSTOMER,
                LoadCustomerContactData::PERFORMANCE_OLD_CONTACT_CUSTOMER,
                LoadCustomerContactData::PERFORMANCE_NO_CONTACT_CUSTOMER,
            ] as $reference
        ) {
            $customer = $this->getReference($reference);
            $source->addFacts(new FactSet(), $customer);
            $durations = [];
            for ($i = 0; $i < 20; $i++) {
                $start = hrtime(true);
                $source->addFacts(new FactSet(), $customer);
                $durations[] = (hrtime(true) - $start) / 1e6;
            }
            sort($durations);

            self::assertLessThanOrEqual(150.0, $durations[(int)ceil(0.95 * \count($durations)) - 1], $reference);
        }
    }

    /**
     * Inserts email activities without owners on the customer user, one minute apart after $start (UTC).
     */
    private function insertEmailActivities(CustomerUser $customerUser, int $count, string $start): void
    {
        $em = self::getContainer()->get('doctrine')->getManagerForClass(ActivityList::class);
        $association = ExtendHelper::buildAssociationName(
            CustomerUser::class,
            ActivityListEntityConfigDumperExtension::ASSOCIATION_KIND
        );
        $joinTable = $em->getClassMetadata(ActivityList::class)->getAssociationMapping($association)['joinTable'];
        $connection = $this->getConnection();
        $connection->executeStatement(
            sprintf(
                'WITH generated AS ('
                . " INSERT INTO oro_activity_list (verb, subject, related_activity_class, related_activity_id,"
                . ' created_at, updated_at, organization_id)'
                . " SELECT 'create', :subject, :class, g,"
                . " CAST(:start AS timestamp) + g * INTERVAL '1 minute',"
                . " CAST(:start AS timestamp) + g * INTERVAL '1 minute', :organization"
                . ' FROM generate_series(1, :count) AS g RETURNING id'
                . ') INSERT INTO %s (%s, %s) SELECT id, :customerUser FROM generated',
                $connection->quoteIdentifier($joinTable['name']),
                $connection->quoteIdentifier($joinTable['joinColumns'][0]['name']),
                $connection->quoteIdentifier($joinTable['inverseJoinColumns'][0]['name'])
            ),
            [
                'subject' => self::GENERATED_SUBJECT,
                'class' => Email::class,
                'start' => $start,
                'organization' => $customerUser->getOrganization()->getId(),
                'count' => $count,
                'customerUser' => $customerUser->getId(),
            ],
            [
                'subject' => ParameterType::STRING,
                'class' => ParameterType::STRING,
                'start' => ParameterType::STRING,
                'organization' => ParameterType::INTEGER,
                'count' => ParameterType::INTEGER,
                'customerUser' => ParameterType::INTEGER,
            ]
        );
    }

    private function getConnection(): Connection
    {
        return self::getContainer()->get('doctrine')->getManagerForClass(ActivityList::class)->getConnection();
    }

    private function getFacts(Customer $customer): FactSet
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')->getFacts($customer);
    }

    private function getSource(): LastContactSource
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.last_contact_source');
    }
}
