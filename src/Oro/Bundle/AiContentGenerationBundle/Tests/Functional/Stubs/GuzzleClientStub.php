<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class GuzzleClientStub extends Client
{
    #[\Override]
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        return new Response();
    }
}
