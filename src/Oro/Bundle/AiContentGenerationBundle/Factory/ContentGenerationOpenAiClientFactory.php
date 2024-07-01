<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationOpenAiClient;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Builds ContentGenerationOpenAiClient with filled up ParameterBag
 */
class ContentGenerationOpenAiClientFactory implements ContentGenerationClientFactoryInterface
{
    private array $additionalParameters = [];

    public function __construct(
        private readonly OpenAiSdkClientFactory $factory,
        private int $maxIterations,
        private int $maxTokens
    ) {
    }

    #[\Override] public function build(ParameterBag $parameterBag): ContentGenerationClientInterface
    {
        if ($this->maxIterations < 1) {
            $this->throwContentGenerationClientException('OpenAI Max Iterations parameter should be greater than 0.');
        }

        $token = $parameterBag->get(OpenAiTransportSettings::TOKEN);
        if (!$token) {
            $this->throwContentGenerationClientException('There is no valid OpenAI Token.');
        }

        $parameterBag->set('maxIterations', $this->maxIterations);
        $parameterBag->set('maxTokens', $this->maxTokens);
        $parameterBag->set('additionalParameters', $this->additionalParameters);

        return new ContentGenerationOpenAiClient(
            $this->factory->getSdkClient($token),
            $parameterBag
        );
    }

    #[\Override] public function addAdditionalParam(string $key, float|int|string|null $value): void
    {
        $this->additionalParameters[$key] = $value;
    }

    #[\Override] public function supports(string $clientName): bool
    {
        return ContentGenerationOpenAiClient::OPEN_AI === $clientName;
    }

    private function throwContentGenerationClientException(string $message): void
    {
        throw new ContentGenerationClientException($message);
    }
}
