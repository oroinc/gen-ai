<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Rfq;

use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqPurchaseData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the RFQ purchase facts: with user-level order access the purchase facts cover only
 * the user's own orders; without class-level VIEW on Order or on OrderLineItem no purchase fact is emitted (never
 * a false zero), while `requested_product_count` still is. Every test runs in its own transaction, so the role
 * changes never outlive it.
 *
 * @dbIsolationPerTest
 */
final class PurchaseHistoryAclTest extends WebTestCase
{
    use RolePermissionExtension;

    private const array PURCHASE_KEYS = [
        'requested_product_count',
        'previously_purchased_product_count',
        'last_purchase_date',
    ];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqPurchaseData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testUserLevelAccessCoversOnlyTheUsersOwnOrders(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, AccessLevel::BASIC_LEVEL);

        // both product-1 orders belong to another user
        self::assertSame(
            [
                'requested_product_count' => 3,
                'previously_purchased_product_count' => 1,
                'last_purchase_date' => LoadRfqPurchaseData::lastYear('07-01'),
            ],
            $this->getPurchaseFacts()
        );
    }

    /**
     * @dataProvider deniedClassDataProvider
     */
    public function testWithoutClassLevelViewOnlyTheRequestedProductCount(string $deniedClass): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, $deniedClass, AccessLevel::NONE_LEVEL);

        self::assertSame(['requested_product_count' => 3], $this->getPurchaseFacts());
    }

    public static function deniedClassDataProvider(): array
    {
        return ['Order' => [Order::class], 'OrderLineItem' => [OrderLineItem::class]];
    }

    /**
     * @return array<string, mixed> the purchase facts of RFQ_PURCHASE by key
     */
    private function getPurchaseFacts(): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference(LoadRfqPurchaseData::RFQ_PURCHASE));
        $values = [];
        foreach ($facts as $fact) {
            if (\in_array($fact->key, self::PURCHASE_KEYS, true)) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
