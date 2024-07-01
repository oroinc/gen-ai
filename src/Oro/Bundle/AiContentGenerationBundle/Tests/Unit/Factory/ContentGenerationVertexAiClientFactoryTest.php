<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Factory;

use Google\Client;
use GuzzleHttp\ClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationVertexAiClient;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationVertexAiClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel;
use Oro\Component\MessageQueue\Util\JSON;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class ContentGenerationVertexAiClientFactoryTest extends TestCase
{
    private ContentGenerationVertexAiClientFactory $factory;

    private Client&MockObject $googleClient;

    private ClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->googleClient = $this->createMock(Client::class);
        $this->httpClient = $this->createMock(ClientInterface::class);

        $this->factory = new ContentGenerationVertexAiClientFactory(
            $this->googleClient,
            $this->httpClient,
            ['scope_name']
        );
    }

    public function testSupports(): void
    {
        self::assertFalse($this->factory->supports('test'));
        self::assertTrue($this->factory->supports(VertexAiChannel::TYPE));
    }

    public function testThatClientReturned(): void
    {
        $parameterBag = new ParameterBag(['config_file' => JSON::encode('config')]);

        $this->googleClient
            ->expects(self::once())
            ->method('setScopes')
            ->with(['scope_name']);

        $this->googleClient
            ->expects(self::once())
            ->method('setAuthConfig')
            ->with('config');

        $this->googleClient
            ->expects(self::once())
            ->method('fetchAccessTokenWithAssertion')
            ->willReturn(['access_token' => 'accessToken']);

        $this->factory->addAdditionalParam('add_key', 'add_value');

        self::assertInstanceOf(ContentGenerationVertexAiClient::class, $this->factory->build($parameterBag));
    }

    public function testThatClientFailedWhenNoConfigFile(): void
    {
        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('Vertex AI config file should not be blank.');

        $this->factory->build(new ParameterBag([]));
    }

    public function testThatClientFailedWhenFetchAccessTokenWithAssertion(): void
    {
        $exception = new \ErrorException('Original exception message');
        $parameterBag = new ParameterBag(['config_file' => JSON::encode('config')]);

        $this->googleClient
            ->expects(self::once())
            ->method('setScopes')
            ->with(['scope_name']);

        $this->googleClient
            ->expects(self::once())
            ->method('setAuthConfig')
            ->with('config');

        $this->googleClient
            ->expects(self::once())
            ->method('fetchAccessTokenWithAssertion')
            ->willThrowException($exception);

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('Connection with vertex_ai cannot be established');

        $this->factory->build($parameterBag);
    }
}
