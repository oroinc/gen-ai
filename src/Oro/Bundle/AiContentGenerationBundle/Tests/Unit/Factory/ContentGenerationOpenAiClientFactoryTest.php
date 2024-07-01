<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationOpenAiClient;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationOpenAiClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class ContentGenerationOpenAiClientFactoryTest extends TestCase
{
    private ContentGenerationOpenAiClientFactory $factory;

    private OpenAiSdkClientFactory&MockObject $openAiSdkClientFactory;

    protected function setUp(): void
    {
        $this->openAiSdkClientFactory  = $this->createMock(OpenAiSdkClientFactory::class);

        $this->factory = new ContentGenerationOpenAiClientFactory(
            $this->openAiSdkClientFactory,
            2,
            1000
        );
    }

    public function testSupports(): void
    {
        self::assertFalse($this->factory->supports('test'));
        self::assertTrue($this->factory->supports(OpenAiChannel::TYPE));
    }

    public function testThatClientReturned(): void
    {
        $parameterBag = new ParameterBag(['token' => 'some token']);
        $this->factory->addAdditionalParam('add_key', 'add_value');

        self::assertInstanceOf(ContentGenerationOpenAiClient::class, $this->factory->build($parameterBag));
    }

    public function testThatClientFailedWhenMaxIterationsParameterNotValid(): void
    {
        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('OpenAI Max Iterations parameter should be greater than 0.');

        $factory = new ContentGenerationOpenAiClientFactory(
            $this->openAiSdkClientFactory,
            0,
            1000
        );

        $factory->build(new ParameterBag(['token' => 'some token']));
    }

    public function testThatClientFailedWhenTokenNotValid(): void
    {
        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('There is no valid OpenAI Token.');

        $this->factory->build(new ParameterBag([]));
    }
}
