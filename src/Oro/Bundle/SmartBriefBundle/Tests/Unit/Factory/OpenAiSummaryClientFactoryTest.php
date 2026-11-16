<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Factory;

use OpenAI\Factory;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;
use Oro\Bundle\SmartBriefBundle\Client\OpenAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Factory\OpenAiSummaryClientFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Covers the OpenAI factory: the timeout, and missing settings that name only the key.
 */
final class OpenAiSummaryClientFactoryTest extends TestCase
{
    private OpenAiSdkClientFactory&MockObject $sdkClientFactory;
    private OpenAiSummaryClientFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->sdkClientFactory = $this->createMock(OpenAiSdkClientFactory::class);
        $this->factory = new OpenAiSummaryClientFactory($this->sdkClientFactory, 10, 'minimal');
    }

    /**
     * @dataProvider invalidTimeoutDataProvider
     */
    public function testConstructorRejectsNonPositiveTimeout(int $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OpenAiSummaryClientFactory($this->sdkClientFactory, $timeout, 'minimal');
    }

    public static function invalidTimeoutDataProvider(): array
    {
        return [[0], [-1]];
    }

    public function testSupports(): void
    {
        self::assertTrue($this->factory->supports('open_ai'));
        self::assertFalse($this->factory->supports('vertex_ai'));
        self::assertFalse($this->factory->supports(''));
    }

    public function testBuildMakesSdkClientThroughFactoryWithOwnHttpClient(): void
    {
        $sdkFactory = new Factory();
        $this->sdkClientFactory->expects(self::once())
            ->method('factory')
            ->willReturn($sdkFactory);
        $this->sdkClientFactory->expects(self::never())
            ->method('getSdkClient');

        $client = $this->factory->build(new ParameterBag(['token' => 'sk-secret', 'model' => 'gpt-5-mini']));

        self::assertInstanceOf(OpenAiSummaryClient::class, $client);
        self::assertSame('open_ai', $client->getProviderType());
        $this->assertFactoryConfigured($sdkFactory, 'sk-secret');
    }

    /**
     * @dataProvider missingSettingDataProvider
     */
    public function testBuildFailsWhenSettingIsMissing(array $settings, string $missingKey): void
    {
        $this->sdkClientFactory->expects(self::never())
            ->method('factory');

        try {
            $this->factory->build(new ParameterBag($settings));
            self::fail('Expected SummaryClientException.');
        } catch (SummaryClientException $exception) {
            self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
            self::assertStringContainsString(sprintf('"%s"', $missingKey), $exception->getMessage());
            self::assertStringNotContainsString('sk-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public static function missingSettingDataProvider(): array
    {
        return [
            'no token' => [['model' => 'gpt-5-mini'], 'token'],
            'empty token' => [['token' => '', 'model' => 'gpt-5-mini'], 'token'],
            'no model' => [['token' => 'sk-secret'], 'model'],
            'empty model' => [['token' => 'sk-secret', 'model' => ''], 'model'],
        ];
    }

    /**
     * OpenAI\Factory is final and exposes no getters, so the assertions read its private state on purpose;
     * a failure here after an SDK upgrade means the coupling moved, not that the factory regressed.
     */
    private function assertFactoryConfigured(Factory $sdkFactory, string $expectedApiKey): void
    {
        $reflection = new \ReflectionClass($sdkFactory);

        self::assertSame($expectedApiKey, $reflection->getProperty('apiKey')->getValue($sdkFactory));

        $guzzle = $reflection->getProperty('httpClient')->getValue($sdkFactory);
        self::assertInstanceOf(\GuzzleHttp\Client::class, $guzzle);
        self::assertSame(10, $guzzle->getConfig(\GuzzleHttp\RequestOptions::TIMEOUT));
    }
}
