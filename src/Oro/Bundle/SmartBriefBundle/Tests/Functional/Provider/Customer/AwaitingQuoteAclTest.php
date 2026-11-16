<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCrossOrganizationQuoteData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerRfqData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the awaiting quote facts: with user-level quote access only the user's own quotes are
 * counted; with organization-level access a quote of the same customer in another organization stays invisible;
 * without class-level VIEW on Quote no quote fact is emitted. Every test runs in its own transaction, so
 * the role changes never outlive it.
 *
 * @dbIsolationPerTest
 */
final class AwaitingQuoteAclTest extends WebTestCase
{
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerRfqData::class, LoadCrossOrganizationQuoteData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testFullAccessSeesEveryQuote(): void
    {
        self::assertSame(
            ['awaiting_quote_count' => 2, 'nearest_quote_expiry_date' => '2099-10-10'],
            $this->getQuoteFacts()
        );
    }

    public function testOrganizationLevelAccessIgnoresOtherOrganizations(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::GLOBAL_LEVEL);

        // the other organization's quote expires first (10-01) but is invisible
        self::assertSame(
            ['awaiting_quote_count' => 2, 'nearest_quote_expiry_date' => '2099-10-10'],
            $this->getQuoteFacts()
        );
    }

    public function testUserLevelAccessCountsOnlyTheUsersOwnQuotes(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::BASIC_LEVEL);

        // the other user's quote expiring on 10-10 is invisible
        self::assertSame(
            ['awaiting_quote_count' => 1, 'nearest_quote_expiry_date' => '2099-10-20'],
            $this->getQuoteFacts()
        );
    }

    public function testWithoutClassLevelViewNoQuoteFacts(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::NONE_LEVEL);

        self::assertSame([], $this->getQuoteFacts());
    }

    /**
     * @return array<string, mixed> the awaiting quote facts by key
     */
    private function getQuoteFacts(): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')
            ->getFacts($this->getReference(LoadCustomerRfqData::ACL_CUSTOMER));
        $values = [];
        foreach ($facts as $fact) {
            if (\in_array($fact->key, ['awaiting_quote_count', 'nearest_quote_expiry_date'], true)) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
