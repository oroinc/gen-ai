<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Rfq;

use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqQuoteData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the RFQ quote facts: with user-level quote access the count and the linked
 * quote cover only the user's own quotes; without class-level VIEW on Quote no quote fact is emitted, not even a
 * zero count. Every test runs in its own transaction, so the role changes never outlive it.
 *
 * @dbIsolationPerTest
 */
final class RfqQuoteAclTest extends WebTestCase
{
    use RolePermissionExtension;

    private const array QUOTE_KEYS = ['active_quote_count', 'quote_for_this_rfq', 'quote_for_this_rfq_status'];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqQuoteData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testUserLevelAccessCoversOnlyTheUsersOwnQuotes(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::BASIC_LEVEL);

        // the other user's draft (43) and accepted quote (45) are invisible, so 41 is the linked quote
        self::assertSame(
            [
                'active_quote_count' => 2,
                'quote_for_this_rfq' => '41',
                'quote_for_this_rfq_status' => 'quote_internal_status.sent_to_customer',
            ],
            $this->getQuoteFacts()
        );
    }

    public function testWithoutClassLevelViewNoQuoteFacts(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::NONE_LEVEL);

        self::assertSame([], $this->getQuoteFacts());
    }

    /**
     * @return array<string, mixed> the quote facts of RFQ_TWO_LINKED by key
     */
    private function getQuoteFacts(): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference(LoadRfqQuoteData::RFQ_TWO_LINKED));
        $values = [];
        foreach ($facts as $fact) {
            if (\in_array($fact->key, self::QUOTE_KEYS, true)) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
