<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Client;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

class ContentGenerationVertexAiClientMock implements ContentGenerationClientInterface
{
    private const WRONG_PROJECT_ID = 'Wrong ID';

    public function __construct(
        private readonly ParameterBag $parameterBag,
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
        return true;
    }

    private function doRequest(): string
    {
        if ($this->parameterBag->get(VertexAiTransportSettings::PROJECT_ID) === self::WRONG_PROJECT_ID) {
            throw new ContentGenerationClientException(
                sprintf('There is no project with projectID: %s.', self::WRONG_PROJECT_ID)
            );
        }

        if (!$this->parameterBag->get(VertexAiTransportSettings::CONFIG_FILE)) {
            throw new ContentGenerationClientException('Vertex AI config file should not be blank.');
        }

        return 'Generated content by Vertex AI';
    }
}
