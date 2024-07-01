<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Feature\Voter;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\AiContentGenerationBundle\Feature\Voter\FeatureVoter;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FeatureVoterTest extends TestCase
{
    private ConfigManager&MockObject $configManager;

    private ManagerRegistry&MockObject $registry;

    private ObjectRepository&MockObject $repository;

    private RequestStack&MockObject $requestStack;

    private FeatureVoter $featureVoter;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->repository = $this->createMock(ObjectRepository::class);
        $this->featureChecker = $this->createMock(FeatureChecker::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->featureVoter = new FeatureVoter(
            $this->configManager,
            $this->registry,
            $this->requestStack,
            ['excluded_page']
        );
    }

    public function testThatChecksSkippedWhenFeatureAnother(): void
    {
        self::assertEquals($this->featureVoter::FEATURE_ABSTAIN, $this->featureVoter->vote('another'));
    }

    public function testThatChecksSkippedWhenPageExcluded(): void
    {
        $request = new Request();

        $request->attributes->set('_route', 'excluded_page');

        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn($request);

        self::assertEquals(
            $this->featureVoter::FEATURE_ABSTAIN,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }

    public function testIsFeatureDisabledWhenConfigManagerHasNotTransport(): void
    {
        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->willReturn(null);

        self::assertEquals(
            $this->featureVoter::FEATURE_DISABLED,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }

    public function testIsFeatureDisabledWhenTransportIsNotFound(): void
    {
        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->willReturn('transport_name');

        $this->registry
            ->method('getRepository')
            ->willReturn($this->repository);

        $this->repository
            ->expects(self::once())
            ->method('find')
            ->willReturn(null);

        self::assertEquals(
            $this->featureVoter::FEATURE_DISABLED,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }

    public function testIsFeatureDisabledWhenTransportChannelNotFound(): void
    {
        $transport = $this->createMock(Transport::class);

        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->willReturn('transport_name');

        $this->registry
            ->method('getRepository')
            ->willReturn($this->repository);

        $this->repository
            ->expects(self::once())
            ->method('find')
            ->willReturn($transport);

        $transport
            ->expects(self::once())
            ->method('getChannel')
            ->willReturn(null);

        self::assertEquals(
            $this->featureVoter::FEATURE_DISABLED,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }

    public function testIsFeatureDisabledWhenTransportChannelIsDisabled(): void
    {
        $transport = $this->createMock(Transport::class);
        $channel = $this->createMock(Channel::class);

        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->willReturn('transport_name');

        $this->registry
            ->method('getRepository')
            ->willReturn($this->repository);

        $this->repository
            ->expects(self::once())
            ->method('find')
            ->willReturn($transport);

        $transport
            ->expects(self::once())
            ->method('getChannel')
            ->willReturn($channel);

        $channel
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(false);

        self::assertEquals(
            $this->featureVoter::FEATURE_DISABLED,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }

    public function testIsFeatureActivated(): void
    {
        $transport = $this->createMock(Transport::class);
        $channel = $this->createMock(Channel::class);

        $this->requestStack
            ->expects(self::once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->willReturn('transport_name');

        $this->registry
            ->method('getRepository')
            ->willReturn($this->repository);

        $this->repository
            ->expects(self::once())
            ->method('find')
            ->willReturn($transport);

        $transport
            ->expects(self::once())
            ->method('getChannel')
            ->willReturn($channel);

        $channel
            ->expects(self::once())
            ->method('isEnabled')
            ->willReturn(true);

        self::assertEquals(
            $this->featureVoter::FEATURE_ENABLED,
            $this->featureVoter->vote('oro_ai_content_generation')
        );
    }
}
