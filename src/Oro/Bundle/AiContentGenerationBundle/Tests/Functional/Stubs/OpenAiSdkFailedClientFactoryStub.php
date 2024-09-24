<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs;

use OpenAI\Contracts\ClientContract;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;

class OpenAiSdkFailedClientFactoryStub extends OpenAiSdkClientFactory
{
    #[\Override]
    public function getSdkClient(string $token, ?string $organization = null): ClientContract
    {
        throw new ContentGenerationClientException('Connection is not stable');
    }
}
