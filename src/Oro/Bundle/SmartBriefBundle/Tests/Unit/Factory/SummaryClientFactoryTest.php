<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Factory;

use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactory;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactoryInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SelectedIntegrationChannelProvider;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\TransportStub;
use Oro\Component\Testing\ReflectionUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the dispatcher.
 */
final class SummaryClientFactoryTest extends TestCase
{
    private const string TOKEN = 'sk-secret-token';

    private SelectedIntegrationChannelProvider&MockObject $channelProvider;
    private SummaryClientFactoryInterface&MockObject $openAiFactory;
    private SummaryClientFactoryInterface&MockObject $vertexAiFactory;
    private SummaryClientFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->channelProvider = $this->createMock(SelectedIntegrationChannelProvider::class);
        $this->openAiFactory = $this->createFactoryMock('open_ai');
        $this->vertexAiFactory = $this->createFactoryMock('vertex_ai');

        $this->factory = new SummaryClientFactory(
            $this->channelProvider,
            [$this->openAiFactory, $this->vertexAiFactory]
        );
    }

    public function testSelectedOpenAiChannelIsBuiltByTheOpenAiFactory(): void
    {
        $channel = $this->createChannel(42, 'open_ai', true);
        $this->expectSelectedChannel(42, $channel);
        $client = $this->createMock(SummaryClientInterface::class);
        $this->openAiFactory->expects(self::once())
            ->method('build')
            ->with(self::identicalTo($channel->getTransport()->getSettingsBag()))
            ->willReturn($client);
        $this->vertexAiFactory->expects(self::never())->method('build');

        self::assertSame($client, $this->factory->getClient());
    }

    public function testSelectedVertexAiChannelIsBuiltByTheVertexAiFactory(): void
    {
        $this->expectSelectedChannel(43, $this->createChannel(43, 'vertex_ai', true));
        $client = $this->createMock(SummaryClientInterface::class);
        $this->vertexAiFactory->expects(self::once())->method('build')->willReturn($client);
        $this->openAiFactory->expects(self::never())->method('build');

        self::assertSame($client, $this->factory->getClient());
    }

    /**
     * @dataProvider unusableSelectionDataProvider
     */
    public function testUnusableSelectionThrowsWithoutBuilding(
        bool $selected,
        ?Channel $channel,
        string $expectedMessage
    ): void {
        $this->channelProvider->expects(self::once())->method('getChannel')->willReturn($channel);
        $this->channelProvider->expects(null === $channel ? self::once() : self::never())
            ->method('getChannelId')
            ->willReturn($selected ? 42 : null);
        $this->openAiFactory->expects(self::never())->method('build');
        $this->vertexAiFactory->expects(self::never())->method('build');

        try {
            $this->factory->getClient();
            self::fail('Expected SummaryClientException.');
        } catch (SummaryClientException $exception) {
            self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
    }

    public static function unusableSelectionDataProvider(): array
    {
        $notSelected = 'No AI integration is selected';

        return [
            'nothing selected' => [false, null, $notSelected],
            'channel deleted' => [true, null, '#42 selected for summaries does not exist'],
            'channel disabled' => [
                true,
                self::createChannelStatic(42, 'open_ai', false),
                '#42 selected for summaries is disabled',
            ],
            'unsupported type' => [
                true,
                self::createChannelStatic(42, 'shipping', true),
                'integration type "shipping"',
            ],
            'no transport' => [
                true,
                self::createChannelStatic(42, 'open_ai', true, false),
                '#42 has no transport settings',
            ],
        ];
    }

    public function testClientExceptionsPassThroughUnchanged(): void
    {
        $this->expectSelectedChannel(42, $this->createChannel(42, 'open_ai', true));
        $exception = new SummaryClientException('rate limited', 'rate_limited', 429, 'rate_limit_exceeded');
        $this->openAiFactory->expects(self::once())->method('build')->willThrowException($exception);

        try {
            $this->factory->getClient();
            self::fail('Expected SummaryClientException.');
        } catch (SummaryClientException $thrown) {
            self::assertSame($exception, $thrown);
        }
    }

    private function expectSelectedChannel(int $id, Channel $channel): void
    {
        self::assertSame($id, $channel->getId());
        $this->channelProvider->expects(self::never())->method('getChannelId');
        $this->channelProvider->expects(self::once())->method('getChannel')->willReturn($channel);
    }

    private function createFactoryMock(string $type): SummaryClientFactoryInterface&MockObject
    {
        $factory = $this->createMock(SummaryClientFactoryInterface::class);
        $factory->expects(self::any())
            ->method('supports')
            ->willReturnCallback(static fn (string $channelType) => $channelType === $type);

        return $factory;
    }

    private function createChannel(int $id, string $type, bool $enabled, bool $withTransport = true): Channel
    {
        return self::createChannelStatic($id, $type, $enabled, $withTransport);
    }

    private static function createChannelStatic(
        int $id,
        string $type,
        bool $enabled,
        bool $withTransport = true
    ): Channel {
        $channel = new Channel();
        ReflectionUtil::setId($channel, $id);
        $channel->setType($type);
        $channel->setEnabled($enabled);
        if ($withTransport) {
            $channel->setTransport(new TransportStub(['token' => self::TOKEN]));
        }

        return $channel;
    }
}
