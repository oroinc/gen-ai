<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Client;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationVertexAiClient;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

final class ContentGenerationVertexAiClientTest extends TestCase
{
    private ContentGenerationVertexAiClient $client;

    private ClientInterface&MockObject $httpClient;

    private ResponseInterface&MockObject $httpResponse;

    private ContentGenerationRequest $request;

    #[\Override]
    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->httpResponse = $this->createMock(ResponseInterface::class);

        $parameters = new ParameterBag([
            'model' => 'model',
            'accessToken' => 'Token',
            'temperature' => 36.6,
            'topP' => 10,
            'topK' => 2,
            'baseUri' => 'site.com/',
            'additionalParameters' => [
                'add_key' => 'add_value'
            ]
        ]);
        $this->request = new ContentGenerationRequest(
            'Generation phrase',
            ['Value' => 'Some text'],
            'Tone',
        );
        $this->request->setMaxTokens(320);

        $this->client = new ContentGenerationVertexAiClient($this->httpClient, $parameters);
    }

    public function testSupportsUserContentSize(): void
    {
        self::assertTrue($this->client->supportsUserContentSize());
    }

    public function testThatResponseGeneratedSuccessfully(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'post',
                'site.com/model:predict',
                [
                    'json' => [
                        'instances' => [
                            [
                                'content' => implode(
                                    "\n",
                                    [$this->request->getClientPrompt(), $this->request->getClientContext()]
                                )
                            ]
                        ],
                        'parameters' => [
                            'temperature' => 36.6,
                            'maxOutputTokens' => 320,
                            'topP' => 10,
                            'topK' => 2,
                            'add_key' => 'add_value'
                        ]
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer Token',
                        'Accept' => 'application/json',
                    ]
                ]
            )
            ->willReturn($this->httpResponse);

        $this->httpResponse
            ->expects(self::once())
            ->method('getBody')
            ->willReturn(json_encode(['predictions' => [['content' => 'generated response']]]));

        self::assertEquals('generated response', $this->client->generateTextContent($this->request));
    }

    public function testClientException(): void
    {
        $stream = $this->createMock(StreamInterface::class);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new ClientException(
                'error message',
                $this->createMock(RequestInterface::class),
                $this->httpResponse
            ));

        $this->httpResponse
            ->expects(self::once())
            ->method('getBody')
            ->willReturn($stream);

        $stream
            ->expects(self::once())
            ->method('getContents')
            ->willReturn(json_encode(['error' => ['message' => 'Full error message']]));

        self::expectExceptionObject(new ContentGenerationClientException('Full error message'));

        $this->client->generateTextContent($this->request);
    }

    public function testConnectException(): void
    {
        $connectException = new ConnectException(
            'Cannot connect',
            $this->createMock(RequestInterface::class)
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'post',
                'site.com/model:predict',
                [
                    'json' => [
                        'instances' => [
                            [
                                'content' => implode(
                                    "\n",
                                    [$this->request->getClientPrompt(), $this->request->getClientContext()]
                                )
                            ]
                        ],
                        'parameters' => [
                            'temperature' => 36.6,
                            'maxOutputTokens' => 320,
                            'topP' => 10,
                            'topK' => 2,
                            'add_key' => 'add_value'
                        ]
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer Token',
                        'Accept' => 'application/json',
                    ]
                ]
            )
            ->willThrowException($connectException);

        self::expectExceptionObject(new ContentGenerationClientException(
            'Connection with vertex_ai cannot be established',
            previous: $connectException
        ));

        $this->client->generateTextContent($this->request);
    }

    public function testThatHandledNotExpectedException(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(new \Exception('some message'));

        self::expectExceptionObject(new ContentGenerationClientException('some message'));

        $this->client->generateTextContent($this->request);
    }

    public function testSuccessfulCheckConnection(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'post',
                'site.com/model:predict',
                [
                    'json' => [
                        'instances' => [
                            [
                                'content' => 'Check connection'
                            ]
                        ]
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer Token',
                        'Accept' => 'application/json',
                    ]
                ]
            );

        $this->client->checkConnection();
    }
}
