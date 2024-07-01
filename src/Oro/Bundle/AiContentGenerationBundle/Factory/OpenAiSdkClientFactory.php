<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Factory;

/**
 *  The factory that provides OpenAi client or factory
 */
class OpenAiSdkClientFactory
{
    public function getSdkClient(string $token, ?string $organization = null): ClientContract
    {
        return OpenAI::client($token, $organization);
    }

    public function factory(): Factory
    {
        return new Factory();
    }
}
