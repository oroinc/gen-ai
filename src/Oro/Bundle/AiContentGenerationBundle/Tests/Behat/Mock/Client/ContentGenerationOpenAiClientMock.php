<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Client;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

class ContentGenerationOpenAiClientMock implements ContentGenerationClientInterface
{
    private const WRONG_TOKEN = 'Wrong token';

    public function __construct(
        private readonly ParameterBag $parameterBag
    ) {
    }

    public function generateTextContent(ContentGenerationRequest $request): string
    {
        return $this->doRequest();
    }

    public function checkConnection(): void
    {
        $this->doRequest();
    }

    public function supportsUserContentSize(): bool
    {
        return false;
    }

    private function doRequest(): string
    {
        if ($this->parameterBag->get(OpenAiTransportSettings::TOKEN) === self::WRONG_TOKEN) {
            throw new ContentGenerationClientException(
                sprintf('Incorrect API key provided: %s.', self::WRONG_TOKEN)
            );
        }

        return 'Generated content by OpenAI';
    }
}
