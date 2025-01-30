<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs;

use Google\Client;
use GuzzleHttp\ClientInterface;

class GoogleClientStub extends Client
{
    #[\Override]
    public function fetchAccessTokenWithAssertion(?ClientInterface $authHttp = null): array
    {
        return ['access_token' => 'token'];
    }

    #[\Override]
    public function setAuthConfig($config): void
    {
    }
}
