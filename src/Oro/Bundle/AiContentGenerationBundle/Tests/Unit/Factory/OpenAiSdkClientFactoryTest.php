<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Factory;

use OpenAI\Client;
use OpenAI\Factory;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;
use PHPUnit\Framework\TestCase;

final class OpenAiSdkClientFactoryTest extends TestCase
{
    private OpenAiSdkClientFactory $sdkFactory;

    protected function setUp(): void
    {
        $this->sdkFactory = new OpenAiSdkClientFactory();
    }

    public function testGetSdkClientWithoutOrganization(): void
    {
        $client = $this->sdkFactory->getSdkClient('some token');

        self::assertInstanceOf(Client::class, $client);
    }

    public function testGetSdkClientWithOrganization(): void
    {
        $client = $this->sdkFactory->getSdkClient('some token', 'oro');

        self::assertInstanceOf(Client::class, $client);
    }

    public function testFactory(): void
    {
        $openAiFactory = $this->sdkFactory->factory();

        self::assertInstanceOf(Factory::class, $openAiFactory);
    }
}
