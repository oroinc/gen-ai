<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs;

use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Responses\Fixtures\Chat\CreateResponseFixture;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;

class OpenAiSdkClientFactoryStub extends OpenAiSdkClientFactory
{
    public function getSdkClient(string $token, ?string $organization = null): ClientContract
    {
        $fake = new ClientFake();

        $fake->addResponses([CreateResponse::from(CreateResponseFixture::ATTRIBUTES)]);

        return $fake;
    }
}
