<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SelectedIntegrationChannelProvider;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use Oro\Component\Testing\ReflectionUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The selected channel id is read for the given scope and the channel loaded by id;
 * a channel of another organization than the scope's or the user's counts as not selected.
 */
final class SelectedIntegrationChannelProviderTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private ObjectRepository&MockObject $repository;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private SelectedIntegrationChannelProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->repository = $this->createMock(ObjectRepository::class);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::any())->method('getRepository')->with(Channel::class)->willReturn($this->repository);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->provider = new SelectedIntegrationChannelProvider($this->configManager, $doctrine, $this->tokenAccessor);
    }

    /**
     * @dataProvider notSelectedDataProvider
     */
    public function testNothingSelected(mixed $configured): void
    {
        $this->configManager->expects(self::any())
            ->method('get')
            ->with('oro_smart_brief.integration', false, false, null)
            ->willReturn($configured);
        $this->repository->expects(self::never())->method('find');

        self::assertNull($this->provider->getChannelId());
        self::assertNull($this->provider->getChannel());
    }

    public static function notSelectedDataProvider(): array
    {
        return [[null], [''], [0], [-1], ['abc']];
    }

    public function testSelectedChannelOfTheUsersOrganization(): void
    {
        $channel = $this->createChannel(1);
        $this->configManager->expects(self::exactly(2))
            ->method('get')
            ->with('oro_smart_brief.integration', false, false, 7)
            ->willReturn('42');
        $this->repository->expects(self::once())->method('find')->with(42)->willReturn($channel);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);

        self::assertSame(42, $this->provider->getChannelId(7));
        self::assertSame($channel, $this->provider->getChannel(7));
    }

    /**
     * @dataProvider otherOrganizationDataProvider
     */
    public function testChannelOfAnotherOrganizationIsNotSelected(?int $userOrganizationId): void
    {
        $this->configManager->expects(self::any())->method('get')->willReturn(42);
        $this->repository->expects(self::once())->method('find')->with(42)->willReturn($this->createChannel(2));
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn($userOrganizationId);

        self::assertNull($this->provider->getChannel());
    }

    public static function otherOrganizationDataProvider(): array
    {
        return ['another organization' => [1], 'no organization' => [null]];
    }

    public function testScopeOrganizationWinsOverTheUsers(): void
    {
        $channel = $this->createChannel(2);
        $website = new Website();
        $website->setOrganization($channel->getOrganization());
        $this->configManager->expects(self::any())->method('get')->willReturn(42);
        $this->repository->expects(self::exactly(2))->method('find')->with(42)->willReturn($channel);
        $this->tokenAccessor->expects(self::never())->method('getOrganizationId');

        self::assertSame($channel, $this->provider->getChannel($website));
        self::assertSame($channel, $this->provider->getChannel($channel->getOrganization()));
    }

    private function createChannel(int $organizationId): Channel
    {
        $organization = new Organization();
        ReflectionUtil::setId($organization, $organizationId);
        $channel = new Channel();
        $channel->setOrganization($organization);

        return $channel;
    }

    public function testDeletedChannel(): void
    {
        $this->configManager->expects(self::any())->method('get')->willReturn(42);
        $this->repository->expects(self::once())->method('find')->with(42)->willReturn(null);

        self::assertNull($this->provider->getChannel());
    }
}
