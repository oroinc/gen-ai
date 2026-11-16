<?php

namespace Oro\Bundle\SmartBriefBundle\Factory;

use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Client\VertexAiSummaryClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Builds the Vertex AI summary client from a Vertex AI integration channel's transport settings.
 *
 * - The service-account JSON is exchanged for an access token, which is cached in the bundle's pool under a hash
 *   of that JSON until 60 seconds before it expires; the token lives only in the pool, in the built
 *   client and in the shared Google client instance.
 * - The configured timeout is the total budget of one call: the time a token exchange takes is deducted from
 *   the generateContent client's timeout.
 * - Any failure of the exchange, including a missing access token, is a {@see SummaryClientException} whose
 *   message carries neither the JSON nor the token.
 */
class VertexAiSummaryClientFactory implements SummaryClientFactoryInterface
{
    private const string TOKEN_CACHE_KEY_PREFIX = 'oro_smart_brief_vertex_token_';
    private const int TOKEN_EXPIRY_MARGIN = 60;
    // Copied from Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationVertexAiClientFactory
    // (BASE_URI_PLACEHOLDER)
    private const string BASE_URI_PLACEHOLDER = 'https://%s/v1/projects/%s/locations/%s/publishers/google/models/%s';

    /**
     * @param string[] $scopes
     */
    public function __construct(
        private readonly GoogleClient $googleClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $timeout,
        private readonly int $thinkingBudget,
        private readonly array $scopes = ['https://www.googleapis.com/auth/cloud-platform']
    ) {
        if ($this->timeout <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'The Vertex AI summary client timeout must be a positive number of seconds, %d given.',
                $timeout
            ));
        }
    }

    #[\Override]
    public function supports(string $channelType): bool
    {
        return VertexAiSummaryClient::PROVIDER_TYPE === $channelType;
    }

    #[\Override]
    public function build(ParameterBag $transportSettings): SummaryClientInterface
    {
        $authConfig = $this->getRequiredSetting($transportSettings, VertexAiTransportSettings::CONFIG_FILE);
        $model = $this->getRequiredSetting($transportSettings, VertexAiTransportSettings::MODEL);
        // every segment is URL-encoded, so a setting can neither add path segments nor a query or user info
        $baseUri = sprintf(
            self::BASE_URI_PLACEHOLDER,
            rawurlencode($this->getRequiredSetting($transportSettings, VertexAiTransportSettings::API_ENDPOINT)),
            rawurlencode($this->getRequiredSetting($transportSettings, VertexAiTransportSettings::PROJECT_ID)),
            rawurlencode(
                (string)$transportSettings->get(VertexAiTransportSettings::LOCATION)
                    ?: VertexAiTransportSettings::DEFAULT_LOCATION
            ),
            rawurlencode($model)
        );

        $started = microtime(true);
        $accessToken = $this->getAccessToken($authConfig);
        $remainingTimeout = max(1, (int)ceil($this->timeout - (microtime(true) - $started)));

        return new VertexAiSummaryClient(
            new GuzzleClient([RequestOptions::TIMEOUT => $remainingTimeout]),
            $baseUri,
            $accessToken,
            $model,
            $this->thinkingBudget
        );
    }

    private function getAccessToken(string $authConfig): string
    {
        $item = $this->cache->getItem(self::TOKEN_CACHE_KEY_PREFIX . hash('sha256', $authConfig));
        if ($item->isHit() && \is_string($item->get()) && '' !== $item->get()) {
            return $item->get();
        }

        $credentials = $this->fetchAccessToken($authConfig);
        $accessToken = $credentials['access_token'] ?? null;
        if (!\is_string($accessToken) || '' === $accessToken) {
            throw new SummaryClientException('The Vertex AI token exchange returned no access token.');
        }

        $expiresIn = (int)($credentials['expires_in'] ?? 0);
        if ($expiresIn > self::TOKEN_EXPIRY_MARGIN) {
            $item->set($accessToken);
            $item->expiresAfter($expiresIn - self::TOKEN_EXPIRY_MARGIN);
            $this->cache->save($item);
        }

        return $accessToken;
    }

    /**
     * Copied from Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationVertexAiClientFactory::getAccessToken,
     * with two differences: every exception becomes a SummaryClientException, and the exchange uses an
     * HTTP client with the configured timeout.
     *
     * @return array<string, mixed>
     */
    private function fetchAccessToken(string $authConfig): array
    {
        try {
            $config = json_decode($authConfig, true, 512, JSON_THROW_ON_ERROR);
            // Google\Client treats a string config as a file path to read; only a service-account object is accepted
            if (
                !\is_array($config)
                || 'service_account' !== ($config['type'] ?? null)
                || empty($config['client_email'])
                || empty($config['private_key'])
            ) {
                throw new SummaryClientException('The Vertex AI configuration is not a service-account key.');
            }
            $this->googleClient->setScopes($this->scopes);
            $this->googleClient->setAuthConfig($config);

            return (array)$this->googleClient->fetchAccessTokenWithAssertion(
                new GuzzleClient([RequestOptions::TIMEOUT => $this->timeout])
            );
        } catch (SummaryClientException $exception) {
            throw $exception;
        } catch (NetworkExceptionInterface) {
            throw new SummaryClientException(
                'The Vertex AI token exchange timed out or the connection failed.',
                SummaryClientException::REASON_TIMEOUT
            );
        } catch (\Throwable $exception) {
            throw new SummaryClientException(
                sprintf('The Vertex AI token exchange failed: %s.', $exception::class)
            );
        }
    }

    private function getRequiredSetting(ParameterBag $transportSettings, string $key): string
    {
        $value = (string)$transportSettings->get($key);
        if ('' === $value) {
            throw new SummaryClientException(
                sprintf('The Vertex AI integration has no "%s" setting.', $key)
            );
        }

        return $value;
    }
}
