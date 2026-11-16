<?php

namespace Oro\Bundle\SmartBriefBundle\Factory;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;
use Oro\Bundle\SmartBriefBundle\Client\OpenAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Builds the OpenAI summary client from an OpenAI integration channel's transport settings.
 *
 * The SDK client gets its own HTTP client so the configured timeout applies to the whole call.
 */
class OpenAiSummaryClientFactory implements SummaryClientFactoryInterface
{
    public function __construct(
        private readonly OpenAiSdkClientFactory $sdkClientFactory,
        private readonly int $timeout,
        private readonly string $reasoningEffort
    ) {
        if ($this->timeout <= 0) {
            throw new \InvalidArgumentException(
                sprintf('The OpenAI summary client timeout must be a positive number of seconds, %d given.', $timeout)
            );
        }
    }

    #[\Override]
    public function supports(string $channelType): bool
    {
        return OpenAiSummaryClient::PROVIDER_TYPE === $channelType;
    }

    #[\Override]
    public function build(ParameterBag $transportSettings): SummaryClientInterface
    {
        $token = $this->getRequiredSetting($transportSettings, OpenAiTransportSettings::TOKEN);
        $model = $this->getRequiredSetting($transportSettings, OpenAiTransportSettings::MODEL);

        $sdkClient = $this->sdkClientFactory->factory()
            ->withApiKey($token)
            ->withHttpClient(new GuzzleClient([RequestOptions::TIMEOUT => $this->timeout]))
            ->make();

        return new OpenAiSummaryClient($sdkClient, $model, $this->reasoningEffort);
    }

    private function getRequiredSetting(ParameterBag $transportSettings, string $key): string
    {
        $value = (string)$transportSettings->get($key);
        if ('' === $value) {
            throw new SummaryClientException(
                sprintf('The OpenAI integration has no "%s" setting.', $key)
            );
        }

        return $value;
    }
}
