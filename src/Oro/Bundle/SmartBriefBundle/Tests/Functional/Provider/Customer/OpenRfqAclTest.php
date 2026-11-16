<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerRfqData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\FactValuesTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the ACL of the open RFQ facts: it applies to the RFQs and, inside the "no live quote" subquery,
 * to the quotes, so a quote the user cannot see neither hides an RFQ nor reveals itself; without class-level VIEW
 * on Request or Quote no RFQ fact is emitted. Every test runs in its own transaction, so the role changes never
 * outlive it.
 *
 * @dbIsolationPerTest
 */
final class OpenRfqAclTest extends WebTestCase
{
    use FactValuesTrait;
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerRfqData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testFullAccessSeesEveryRfqAndQuote(): void
    {
        // the RFQ quoted by another user has a live draft quote, so it is not counted
        self::assertSame([2, '2026-05-01'], $this->getOpenRfqValues());
    }

    public function testUserLevelRfqAccessCountsOnlyTheUsersOwnRfqs(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Request::class, AccessLevel::BASIC_LEVEL);

        // the older RFQ of another user is invisible
        self::assertSame([1, '2026-06-01'], $this->getOpenRfqValues());
    }

    public function testAQuoteTheUserCannotSeeDoesNotHideItsRfq(): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Quote::class, AccessLevel::BASIC_LEVEL);

        // the other user's live draft quote is invisible, so its RFQ counts as awaiting our quote
        self::assertSame([3, '2026-05-01'], $this->getOpenRfqValues());
    }

    /**
     * @dataProvider deniedClassDataProvider
     */
    public function testWithoutClassLevelViewNoRfqFacts(string $deniedClass): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, $deniedClass, AccessLevel::NONE_LEVEL);

        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')
            ->getFacts($this->getReference(LoadCustomerRfqData::ACL_CUSTOMER));
        $keys = array_map(static fn ($fact) => $fact->key, array_values(iterator_to_array($facts)));

        self::assertNotContains('open_rfq_count', $keys);
        self::assertNotContains('oldest_open_rfq_date', $keys);
    }

    public static function deniedClassDataProvider(): array
    {
        return [
            'Request' => [Request::class],
            'Quote' => [Quote::class],
        ];
    }

    /**
     * @return array{0: int, 1: ?string} the count and the oldest RFQ's date
     */
    private function getOpenRfqValues(): array
    {
        $source = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.open_rfq_source');
        $customer = $this->getReference(LoadCustomerRfqData::ACL_CUSTOMER);
        $facts = $this->getFactValues(static fn (FactSet $facts) => $source->addFacts($facts, $customer));

        return [$facts['open_rfq_count'], $facts['oldest_open_rfq_date'] ?? null];
    }
}
