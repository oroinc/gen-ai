<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCrossOrganizationOrderData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerOrderData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the last order facts: without class-level VIEW on Order no order fact is
 * emitted; with user-level access the facts describe only the user's own orders; with organization-level access
 * a newer order of the same customer in another organization stays invisible. Every test runs in its own
 * transaction, so the role changes never outlive it.
 *
 * @dbIsolationPerTest
 */
final class LastOrderAclTest extends WebTestCase
{
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerOrderData::class, LoadCrossOrganizationOrderData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testWithoutClassLevelViewNoOrderFacts(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::NONE_LEVEL);

        self::assertSame([], array_values(array_filter(
            $this->getEmittedKeys(),
            static fn (string $key) => str_starts_with($key, 'last_order_')
        )));
    }

    public function testUserLevelAccessSeesOnlyTheUsersOwnOrders(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::BASIC_LEVEL);

        // the newer order without status belongs to another user, so the user's own open order is the last one
        self::assertSame(['2026-08-14', '12480.0000', 'USD'], $this->getLastOrderValues());
    }

    public function testOrganizationLevelAccessIgnoresOtherOrganizations(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::GLOBAL_LEVEL);

        // the 10-05 order of the other organization is newer but invisible
        self::assertSame(['2026-09-10', '500.0000', 'EUR'], $this->getLastOrderValues());
    }

    /**
     * @return array{0: string, 1: string, 2: string} the last order's date, amount and currency
     */
    private function getLastOrderValues(): array
    {
        $facts = $this->getFacts();
        $byKey = [];
        foreach ($facts as $fact) {
            $byKey[$fact->key] = $fact;
        }
        self::assertArrayHasKey('last_order_date', $byKey);
        self::assertArrayHasKey('last_order_total', $byKey);
        [$date, $total] = [$byKey['last_order_date'], $byKey['last_order_total']];

        return [$date->value, $total->value->getValue(), $total->value->getCurrency()];
    }

    private function getFacts(): FactSet
    {
        return self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')
            ->getFacts($this->getReference(LoadCustomerOrderData::CUSTOMER));
    }

    /**
     * @return string[]
     */
    private function getEmittedKeys(): array
    {
        return $this->keys($this->getFacts());
    }

    /**
     * @return string[]
     */
    private function keys(FactSet $facts): array
    {
        return array_map(static fn ($fact) => $fact->key, array_values(iterator_to_array($facts)));
    }
}
