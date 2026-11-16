<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\ActivityListBundle\Entity\ActivityList;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Entity\EmailUser;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\ContactActivityClasses;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerContactData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the last contact facts: the facts reflect the activities the Activity list
 * shows the user. Email access is decided on `EmailUser`: with user-level access the other user's newer email is
 * ignored (an email without owner stays visible, as in the widget); without class-level VIEW no email counts, not
 * even one without owner, while the other channels still do. Without VIEW on customer users their activities are
 * not searched (one query instead of two), and without VIEW on any contact activity class nothing is queried. Every
 * test runs in its own transaction, so the role changes never outlive it.
 *
 * @dbIsolationPerTest
 */
final class LastContactAclTest extends WebTestCase
{
    use QueryCountTrait;
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        if (!LoadCustomerContactData::isApplicable()) {
            self::markTestSkipped('The call, conversation and task packages are not installed.');
        }
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerContactData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testFullAccessSeesTheNewestEmail(): void
    {
        self::assertSame(
            ['last_contact_date' => '2026-10-04', 'last_contact_channel' => 'email'],
            $this->getContactFacts()
        );
    }

    public function testUserLevelEmailAccessIgnoresAnotherUsersEmail(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, EmailUser::class, AccessLevel::BASIC_LEVEL);

        // the other user's 10-04 email is invisible; the 10-02 email without owner is visible, as in the widget
        self::assertSame(
            ['last_contact_date' => '2026-10-02', 'last_contact_channel' => 'email'],
            $this->getContactFacts()
        );
    }

    public function testWithoutClassLevelViewOnEmailUserOnlyOtherChannelsCount(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, EmailUser::class, AccessLevel::NONE_LEVEL);

        // no email counts, not even the one without owner; the older call still does
        self::assertSame(
            ['last_contact_date' => '2026-09-25', 'last_contact_channel' => 'call'],
            $this->getContactFacts()
        );
    }

    public function testWithoutViewOnCustomerUsersTheirActivitiesAreNotSearched(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, CustomerUser::class, AccessLevel::NONE_LEVEL);

        self::assertSame([], $this->getContactFacts());
        self::assertSame(1, $this->countSourceQueries(), 'Only the customer query runs.');
    }

    public function testWithoutViewOnAnyContactActivityNothingIsQueried(): void
    {
        $classes = [EmailUser::class, ContactActivityClasses::CALL_CLASS, ContactActivityClasses::CONVERSATION_CLASS];
        foreach ($classes as $class) {
            if (class_exists($class)) {
                $this->updateRolePermission(User::ROLE_ADMINISTRATOR, $class, AccessLevel::NONE_LEVEL);
            }
        }

        self::assertSame([], $this->getContactFacts());
        self::assertSame(0, $this->countSourceQueries());
    }

    private function countSourceQueries(): int
    {
        $source = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.last_contact_source');
        $customer = $this->getReference(LoadCustomerContactData::ACL_CUSTOMER);
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $customer);

        return $this->countQueries(ActivityList::class, static fn () => $source->addFacts(new FactSet(), $customer));
    }

    /**
     * @return array<string, mixed> the last contact facts by key
     */
    private function getContactFacts(): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')
            ->getFacts($this->getReference(LoadCustomerContactData::ACL_CUSTOMER));
        $values = [];
        foreach ($facts as $fact) {
            if (str_starts_with($fact->key, 'last_contact_')) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
