<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationOpenAiClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Tests\Behat\Mock\Client\ContentGenerationOpenAiClientMock;
use Symfony\Component\HttpFoundation\ParameterBag;

class ContentGenerationOpenAiClientFactoryMock extends ContentGenerationOpenAiClientFactory
{
    public function build(ParameterBag $parameterBag): ContentGenerationClientInterface
    {
        return new ContentGenerationOpenAiClientMock($parameterBag);
    }
}
