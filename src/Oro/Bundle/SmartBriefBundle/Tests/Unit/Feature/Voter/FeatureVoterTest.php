<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Feature\Voter;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\SmartBriefBundle\Feature\Voter\FeatureVoter;
use Oro\Bundle\SmartBriefBundle\Provider\SelectedIntegrationChannelProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FeatureVoterTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private SelectedIntegrationChannelProvider&MockObject $channelProvider;
    private RequestStack&MockObject $requestStack;
    private FeatureVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->channelProvider = $this->createMock(SelectedIntegrationChannelProvider::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->voter = new FeatureVoter(
            $this->configManager,
            $this->channelProvider,
            $this->requestStack,
            ['oro_config_configuration_system', 'oro_organization_config']
        );
    }

    public function testVoteAbstainsForAnotherFeature(): void
    {
        $this->requestStack->expects(self::never())
            ->method('getCurrentRequest');
        $this->configManager->expects(self::never())
            ->method('get');

        self::assertSame(FeatureVoter::FEATURE_ABSTAIN, $this->voter->vote('another_feature'));
    }

    /**
     * @dataProvider excludedRouteDataProvider
     */
    public function testVoteAbstainsOnExcludedRoute(string $route): void
    {
        $request = new Request();
        $request->attributes->set('_route', $route);
        $this->requestStack->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn($request);
        $this->configManager->expects(self::never())
            ->method('get');

        self::assertSame(FeatureVoter::FEATURE_ABSTAIN, $this->voter->vote(FeatureVoter::FEATURE_NAME));
    }

    public static function excludedRouteDataProvider(): array
    {
        return [
            ['oro_config_configuration_system'],
            ['oro_organization_config'],
        ];
    }

    public function testVoteDisabledWhenToggleIsOff(): void
    {
        $this->expectRequestRoute('oro_customer_view');
        $this->configManager->expects(self::once())
            ->method('get')
            ->with('oro_smart_brief.enabled', false, false, null)
            ->willReturn(false);
        $this->channelProvider->expects(self::never())->method('getChannel');

        self::assertSame(FeatureVoter::FEATURE_DISABLED, $this->voter->vote(FeatureVoter::FEATURE_NAME));
    }

    /**
     * @dataProvider channelDataProvider
     */
    public function testVoteDependsOnTheSelectedChannel(?Channel $channel, int $expected): void
    {
        $this->expectRequestRoute('oro_customer_view');
        $this->expectEnabled(true, null);
        $this->channelProvider->expects(self::once())->method('getChannel')->with(null)->willReturn($channel);

        self::assertSame($expected, $this->voter->vote(FeatureVoter::FEATURE_NAME));
    }

    public static function channelDataProvider(): array
    {
        return [
            'none selected or deleted' => [null, FeatureVoter::FEATURE_DISABLED],
            'disabled channel' => [self::createChannelStatic(false), FeatureVoter::FEATURE_DISABLED],
            'enabled channel' => [self::createChannelStatic(true), FeatureVoter::FEATURE_ENABLED],
        ];
    }

    public function testVotePassesScopeIdentifierThrough(): void
    {
        $this->expectRequestRoute('oro_customer_view');
        $this->expectEnabled(true, 7);
        $this->channelProvider->expects(self::once())
            ->method('getChannel')
            ->with(7)
            ->willReturn(self::createChannelStatic(true));

        self::assertSame(FeatureVoter::FEATURE_ENABLED, $this->voter->vote(FeatureVoter::FEATURE_NAME, 7));
    }

    public function testVoteWithoutRequest(): void
    {
        $this->requestStack->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(null);
        $this->expectEnabled(false, null);

        self::assertSame(FeatureVoter::FEATURE_DISABLED, $this->voter->vote(FeatureVoter::FEATURE_NAME));
    }

    private function expectRequestRoute(?string $route): void
    {
        $request = new Request();
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        $this->requestStack->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn($request);
    }

    private function expectEnabled(bool $enabled, ?int $scopeIdentifier): void
    {
        $this->configManager->expects(self::once())
            ->method('get')
            ->with('oro_smart_brief.enabled', false, false, $scopeIdentifier)
            ->willReturn($enabled);
    }

    private static function createChannelStatic(bool $enabled): Channel
    {
        $channel = new Channel();
        $channel->setEnabled($enabled);

        return $channel;
    }
}
