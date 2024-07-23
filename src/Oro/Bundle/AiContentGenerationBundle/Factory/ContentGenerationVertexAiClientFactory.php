<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use Google\Client as GoogleClient;
use GuzzleHttp\ClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationVertexAiClient;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Builds ContentGenerationVertexClient with filled up VertexClientParameters
 */
class ContentGenerationVertexAiClientFactory implements ContentGenerationClientFactoryInterface
{
    private const BASE_URI_PLACEHOLDER = 'https://%s/v1/projects/%s/locations/%s/publishers/google/models/';

    private array $additionalParameters = [];

    public function __construct(
        private readonly GoogleClient $googleClient,
        private readonly ClientInterface $httpClient,
        private array $cloudPlatformScopes,
        private float $temperature = 0.2,
        private float $topP = 0.8,
        private float $topK = 40,
    ) {
    }

    public function build(ParameterBag $parameterBag): ContentGenerationClientInterface
    {
        $authConfig = $parameterBag->get(VertexAiTransportSettings::CONFIG_FILE);

        if (!$authConfig) {
            throw new ContentGenerationClientException('Vertex AI config file should not be blank.');
        }

        $parameterBag->set('accessToken', $this->getAccessToken($authConfig));
        $parameterBag->set('temperature', $this->temperature);
        $parameterBag->set('topP', $this->topP);
        $parameterBag->set('topK', $this->topK);
        $parameterBag->set('baseUri', $this->getBaseUri($parameterBag));
        $parameterBag->set('additionalParameters', $this->additionalParameters);

        return new ContentGenerationVertexAiClient($this->httpClient, $parameterBag);
    }

    public function addAdditionalParam(string $key, int|float|string|null $value): void
    {
        $this->additionalParameters[$key] = $value;
    }

    public function supports(string $clientName): bool
    {
        return ContentGenerationVertexAiClient::VERTEX_AI === $clientName;
    }

    private function getAccessToken(string $authConfig): string
    {
        $this->googleClient->setScopes($this->cloudPlatformScopes);

        try {
            $this->googleClient->setAuthConfig(json_decode($authConfig, true));

            $tokenId = $this->googleClient->fetchAccessTokenWithAssertion();
        } catch (\ErrorException $exception) {
            throw ContentGenerationClientException::clientConnection(
                ContentGenerationVertexAiClient::VERTEX_AI,
                $exception
            );
        }

        return $tokenId['access_token'] ?? '';
    }

    private function getBaseUri(ParameterBag $parameterBag): string
    {
        return sprintf(
            self::BASE_URI_PLACEHOLDER,
            $parameterBag->get(VertexAiTransportSettings::API_ENDPOINT),
            $parameterBag->get(VertexAiTransportSettings::PROJECT_ID),
            $parameterBag->get(VertexAiTransportSettings::LOCATION),
            $parameterBag->get(VertexAiTransportSettings::MODEL),
        );
    }
}
