<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider;

use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\EmailBundle\Entity\EmailUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Test\Functional\RolePermissionExtension;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Covers the class-level VIEW check against the real ACL: a role without VIEW is refused, any VIEW level is granted at
 * class level (row-level narrowing is the query's job), and a class without ACL protection is refused. The widget
 * record checks are covered by SummaryWidgetControllerTest.
 */
final class SummaryAccessProviderTest extends WebTestCase
{
    use RolePermissionExtension;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    /**
     * @dataProvider accessLevelDataProvider
     */
    public function testClassLevelViewFollowsTheRole(int $accessLevel, bool $expected): void
    {
        $this->updateRolePermission(User::ROLE_ADMINISTRATOR, Order::class, $accessLevel);

        self::assertSame($expected, $this->getAccessProvider()->canView(Order::class));
    }

    public static function accessLevelDataProvider(): array
    {
        return [
            'none' => [AccessLevel::NONE_LEVEL, false],
            'user' => [AccessLevel::BASIC_LEVEL, true],
            'global' => [AccessLevel::GLOBAL_LEVEL, true],
        ];
    }

    public function testClassWithoutAclProtectionIsRefused(): void
    {
        $accessProvider = $this->getAccessProvider();
        self::assertFalse($accessProvider->canView(Email::class), 'Email is not ACL-protected; EmailUser is.');
        self::assertTrue($accessProvider->canView(EmailUser::class));
    }

    private function getAccessProvider(): SummaryAccessProvider
    {
        return self::getContainer()->get('oro_smart_brief.tests.provider.summary_access');
    }
}
