<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationVertexAiClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Client\ContentGenerationVertexAiClientMock;
use Symfony\Component\HttpFoundation\ParameterBag;

class ContentGenerationVertexAiClientFactoryMock extends ContentGenerationVertexAiClientFactory
{
    #[\Override]
    public function build(ParameterBag $parameterBag): ContentGenerationClientInterface
    {
        return new ContentGenerationVertexAiClientMock($parameterBag);
    }
}
