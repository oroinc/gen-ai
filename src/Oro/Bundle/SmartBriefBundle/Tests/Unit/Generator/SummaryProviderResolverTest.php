<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Generator;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryProviderResolver;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderRegistry;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\CustomerStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Feature off, no provider for the class, or the provider's switch off → no summary.
 */
final class SummaryProviderResolverTest extends TestCase
{
    private FeatureChecker&MockObject $featureChecker;
    private SummaryFactProviderRegistry&MockObject $registry;
    private ConfigManager&MockObject $configManager;
    private SummaryFactProviderInterface&MockObject $provider;
    private SummaryProviderResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->featureChecker = $this->createMock(FeatureChecker::class);
        $this->registry = $this->createMock(SummaryFactProviderRegistry::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->provider = $this->createMock(SummaryFactProviderInterface::class);
        $this->provider->expects(self::any())
            ->method('getEnabledConfigKey')
            ->willReturn('oro_smart_brief.customer_enabled');
        $this->resolver = new SummaryProviderResolver($this->featureChecker, $this->registry, $this->configManager);
    }

    public function testFeatureDisabled(): void
    {
        $this->featureChecker->expects(self::once())
            ->method('isFeatureEnabled')
            ->with('oro_smart_brief')
            ->willReturn(false);
        $this->registry->expects(self::never())->method('getProvider');

        self::assertNull($this->resolver->resolve(new CustomerStub()));
    }

    public function testNoProviderForTheClass(): void
    {
        $this->featureChecker->expects(self::once())->method('isFeatureEnabled')->willReturn(true);
        $this->registry->expects(self::once())
            ->method('getProvider')
            ->with(CustomerStub::class)
            ->willReturn(null);
        $this->configManager->expects(self::never())->method('get');

        self::assertNull($this->resolver->resolve(new CustomerStub()));
    }

    /**
     * @dataProvider switchDataProvider
     */
    public function testProviderSwitch(mixed $switch, bool $expected): void
    {
        $this->featureChecker->expects(self::once())->method('isFeatureEnabled')->willReturn(true);
        $this->registry->expects(self::once())->method('getProvider')->willReturn($this->provider);
        $this->configManager->expects(self::once())
            ->method('get')
            ->with('oro_smart_brief.customer_enabled')
            ->willReturn($switch);

        self::assertSame($expected ? $this->provider : null, $this->resolver->resolve(new CustomerStub()));
    }

    public static function switchDataProvider(): array
    {
        return ['on' => [true, true], 'off' => [false, false], 'unset' => [null, false]];
    }

    public function testFindProviderIgnoresTheFeatureAndTheSwitch(): void
    {
        $this->featureChecker->expects(self::never())->method('isFeatureEnabled');
        $this->configManager->expects(self::never())->method('get');
        $this->registry->expects(self::exactly(2))
            ->method('getProvider')
            ->with(CustomerStub::class)
            ->willReturnOnConsecutiveCalls($this->provider, null);

        self::assertSame($this->provider, $this->resolver->findProvider(CustomerStub::class));
        self::assertNull($this->resolver->findProvider(CustomerStub::class));
    }

    /**
     * @dataProvider ineligibilityReasonDataProvider
     */
    public function testIneligibilityReason(bool $feature, bool $hasProvider, mixed $switch, ?string $expected): void
    {
        $this->featureChecker->expects(self::once())->method('isFeatureEnabled')->willReturn($feature);
        $this->registry->expects(self::exactly($feature ? 1 : 0))
            ->method('getProvider')
            ->willReturn($hasProvider ? $this->provider : null);
        $this->configManager->expects(self::exactly($feature && $hasProvider ? 1 : 0))
            ->method('get')
            ->with('oro_smart_brief.customer_enabled')
            ->willReturn($switch);

        self::assertSame($expected, $this->resolver->getIneligibilityReason(CustomerStub::class));
    }

    public static function ineligibilityReasonDataProvider(): array
    {
        return [
            'feature off' => [false, true, true, 'feature_off'],
            'no provider' => [true, false, true, 'no_provider'],
            'switch off' => [true, true, false, 'switch_off'],
            'switch unset' => [true, true, null, 'switch_off'],
            'eligible' => [true, true, true, null],
        ];
    }

    public function testResolveForClassDecidesWithoutAnInstance(): void
    {
        $this->featureChecker->expects(self::once())
            ->method('isFeatureEnabled')
            ->with('oro_smart_brief')
            ->willReturn(true);
        $this->registry->expects(self::once())
            ->method('getProvider')
            ->with(CustomerStub::class)
            ->willReturn($this->provider);
        $this->configManager->expects(self::once())
            ->method('get')
            ->with('oro_smart_brief.customer_enabled')
            ->willReturn(true);

        self::assertSame($this->provider, $this->resolver->resolveForClass(CustomerStub::class));
    }
}
