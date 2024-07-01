<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Factory;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\DependencyInjection\Configuration;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationClientFactoryInterface;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ContentGenerationClientFactoryTest extends TestCase
{
    private ConfigManager&MockObject $configManager;

    private ManagerRegistry&MockObject $register;

    private ContentGenerationClientFactoryInterface&MockObject $clientFactory;

    private ContentGenerationClientFactory $factory;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->register = $this->createMock(ManagerRegistry::class);
        $this->clientFactory = $this->createMock(ContentGenerationClientFactoryInterface::class);

        $this->factory = new ContentGenerationClientFactory(
            $this->configManager,
            $this->register,
            [$this->clientFactory]
        );
    }

    public function testThatClientReturnedWithChannel(): void
    {
        $channel = new Channel();
        $channel->setType(OpenAiChannel::TYPE);
        $transport = new OpenAiTransportSettings();
        $channel->setTransport($transport);

        $clientMock = $this->createMock(ContentGenerationClientInterface::class);

        $this->clientFactory
            ->expects(self::once())
            ->method('supports')
            ->with($channel->getType())
            ->willReturn(true);

        $this->clientFactory
            ->expects(self::once())
            ->method('build')
            ->with($transport->getSettingsBag())
            ->willReturn($clientMock);

        self::assertInstanceOf(ContentGenerationClientInterface::class, $this->factory->getClient($channel));
    }

    public function testThatActivatedClientReturned(): void
    {
        $channel = new Channel();
        $channel->setType(OpenAiChannel::TYPE);
        $transport = new OpenAiTransportSettings();
        $channel->setTransport($transport);

        $clientMock = $this->createMock(ContentGenerationClientInterface::class);
        $repository = $this->createMock(ObjectRepository::class);

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->with(Configuration::getConfigKeyByName(Configuration::GENERATOR_TYPE_KEY))
            ->willReturn('1');

        $this->register->expects(self::once())
            ->method('getRepository')
            ->with(Channel::class)
            ->willReturn($repository);

        $repository->expects(self::once())
            ->method('find')
            ->with('1')
            ->willReturn($channel);

        $this->clientFactory
            ->expects(self::once())
            ->method('supports')
            ->with($channel->getType())
            ->willReturn(true);

        $this->clientFactory
            ->expects(self::once())
            ->method('build')
            ->with($transport->getSettingsBag())
            ->willReturn($clientMock);

        self::assertInstanceOf(ContentGenerationClientInterface::class, $this->factory->getActivatedClient());
    }

    public function testThatExceptionThrowsWhenFeatureDisabled(): void
    {
        $featureChecker = $this->createMock(FeatureChecker::class);

        $this->factory->setFeatureChecker($featureChecker);
        $this->factory->addFeature('feature');

        $featureChecker
            ->expects(self::once())
            ->method('isFeatureEnabled')
            ->willReturn(false);

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('There is no activated Content AI Generation Client');

        $this->factory->getActivatedClient();
    }

    public function testThatExceptionThrowsWhenClientNoSupports(): void
    {
        $channel = new Channel();

        $repository = $this->createMock(ObjectRepository::class);

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->with(Configuration::getConfigKeyByName(Configuration::GENERATOR_TYPE_KEY))
            ->willReturn('1');

        $this->register
            ->expects(self::once())
            ->method('getRepository')
            ->with(Channel::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('find')
            ->with('1')
            ->willReturn($channel);

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('There is no valid integration type for Content AI Generation Client');

        $this->factory->getActivatedClient();
    }

    public function testThatExceptionThrowsWhenClientFactoryNotFound(): void
    {
        $channel = new Channel();
        $channel->setType(OpenAiChannel::TYPE);
        $transport = new OpenAiTransportSettings();
        $channel->setTransport($transport);

        $repository = $this->createMock(ObjectRepository::class);

        $this->configManager
            ->expects(self::once())
            ->method('get')
            ->with(Configuration::getConfigKeyByName(Configuration::GENERATOR_TYPE_KEY))
            ->willReturn('1');

        $this->register
            ->expects(self::once())
            ->method('getRepository')
            ->with(Channel::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('find')
            ->with('1')
            ->willReturn($channel);

        $this->clientFactory
            ->expects(self::once())
            ->method('supports')
            ->with($channel->getType())
            ->willReturn(false);

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('There is no valid integration type for Content AI Generation Client');

        $this->factory->getActivatedClient();
    }
}
