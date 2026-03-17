<?php

namespace Oro\Bundle\AiContentGenerationBundle\Client;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Provides VertexAI Client functionality
 */
class ContentGenerationVertexAiClient implements ContentGenerationClientInterface
{
    public const  VERTEX_AI = 'vertex_ai';

    private const GENERATE_CONTENT_SUFFIX = ':generateContent';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ParameterBag $parameterBag,
    ) {
    }

    #[\Override] public function generateTextContent(ContentGenerationRequest $request): string
    {
        return $this->processRequest(function () use ($request) {
            $response = $this->httpClient->request(
                'post',
                $this->getGenerateContentUri(),
                [
                    'json' => $this->buildPayload($request),
                    'headers' => $this->getHeaders()
                ]
            );

            $body = (string)$response->getBody();
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text === null) {
                throw new ContentGenerationClientException(
                    sprintf('Unexpected Vertex AI response structure. Response: %s', $body ?: '(empty)')
                );
            }

            return (string)$text;
        });
    }

    #[\Override] public function checkConnection(): void
    {
        $this->processRequest(function () {
            $this->httpClient->request(
                'post',
                $this->getGenerateContentUri(),
                [
                    'json' => [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [
                                    ['text' => 'Check connection']
                                ]
                            ]
                        ]
                    ],
                    'headers' => $this->getHeaders()
                ]
            );
        });
    }

    #[\Override] public function supportsUserContentSize(): bool
    {
        return true;
    }

    private function processRequest(callable $request): mixed
    {
        try {
            return $request();
        } catch (ConnectException $exception) {
            throw ContentGenerationClientException::clientConnection(
                static::VERTEX_AI,
                $exception
            );
        } catch (ClientException $exception) {
            $context = json_decode((string)$exception->getResponse()->getBody()->getContents(), true);

            throw new ContentGenerationClientException($context['error']['message']);
        } catch (\Exception $exception) {
            throw new ContentGenerationClientException(
                $exception->getMessage()
            );
        }
    }

    private function getHeaders(): array
    {
        return [
            'Authorization' => sprintf('Bearer %s', $this->parameterBag->get('accessToken')),
            'Accept' => 'application/json',
        ];
    }

    private function getGenerateContentUri(): string
    {
        return $this->parameterBag->get('baseUri') . self::GENERATE_CONTENT_SUFFIX;
    }

    private function buildPayload(ContentGenerationRequest $request): array
    {
        $messages = [
            $request->getClientPrompt(),
            $request->getClientContext()
        ];

        $generationConfig = [
            'maxOutputTokens' => $request->getMaxTokens(),
            'temperature' => $this->parameterBag->get('temperature'),
            'topP' => $this->parameterBag->get('topP'),
            'topK' => $this->parameterBag->get('topK'),
            ...$this->parameterBag->get('additionalParameters', [])
        ];

        return [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => implode("\n", $messages)]
                    ]
                ]
            ],
            'generationConfig' => $generationConfig
        ];
    }
}
