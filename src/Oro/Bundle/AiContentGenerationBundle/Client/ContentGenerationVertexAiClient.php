<?php

namespace Oro\Bundle\AiContentGenerationBundle\Client;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Provides VertexAI Client functionality
 */
class ContentGenerationVertexAiClient implements ContentGenerationClientInterface
{
    public const  VERTEX_AI = 'vertex_ai';

    private const  PREDICT_REQUEST_PLACEHOLDER = '%s:predict';

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
                $this->getPredictUri(),
                [
                    'json' => $this->buildPayload($request),
                    'headers' => $this->getHeaders()
                ]
            );

            $response = json_decode((string)$response->getBody(), true);

            return $response['predictions'][0]['content'];
        });
    }

    #[\Override] public function checkConnection(): void
    {
        $this->processRequest(function () {
            $this->httpClient->request(
                'post',
                $this->getPredictUri(),
                [
                    'json' => [
                        'instances' => [
                            [
                                'content' => 'Check connection'
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

    private function getPredictUri(): string
    {
        $endpoint = sprintf(
            self::PREDICT_REQUEST_PLACEHOLDER,
            $this->parameterBag->get(VertexAiTransportSettings::MODEL)
        );

        return $this->parameterBag->get('baseUri') . $endpoint;
    }

    private function buildPayload(ContentGenerationRequest $request): array
    {
        $messages = [
            $request->getClientPrompt(),
            $request->getClientContext()
        ];

        return [
            'instances' => [
                [
                    'content' => implode("\n", $messages)
                ]
            ],
            'parameters' => [
                'maxOutputTokens' => $request->getMaxTokens(),
                'temperature' => $this->parameterBag->get('temperature'),
                'topP' => $this->parameterBag->get('topP'),
                'topK' => $this->parameterBag->get('topK'),
                ...$this->parameterBag->get('additionalParameters', [])
            ]
        ];
    }
}
