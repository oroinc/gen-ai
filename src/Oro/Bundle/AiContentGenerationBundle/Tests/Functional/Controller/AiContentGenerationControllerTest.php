<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Controller;

use Oro\Bundle\AiContentGenerationBundle\Tests\Functional\DataFixtures\LoadAiContentGenerationChannelData;
use Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs\OpenAiSdkClientFactoryStub;
use Oro\Bundle\AiContentGenerationBundle\Tests\Functional\Stubs\OpenAiSdkFailedClientFactoryStub;
use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * @dbIsolationPerTest
 */
final class AiContentGenerationControllerTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;

    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());

        $this->loadFixtures([
            LoadAiContentGenerationChannelData::class
        ]);
    }

    public function testThatErrorReturnedWhenConnectionIsNotEstablished(): void
    {
        $channel = $this->getReference('open_ai_channel');

        self::getContainer()->set(
            'oro_ai_content_generation.factory.test.open_ai_sdk_factory',
            new OpenAiSdkFailedClientFactoryStub()
        );

        $this->ajaxRequest(
            'POST',
            $this->getUrl('oro_ai_content_generation_validate_connection', ['channelId' => $channel->getId()])
        );

        $result = $this->client->getResponse();

        self::assertJsonResponseStatusCodeEquals($result, 200);
        self::assertEquals(
            [
                'success' => false,
                'message' => 'Connection could not be established'
            ],
            json_decode($result->getContent(), true)
        );
    }

    public function testThatOpenAiClientConnectionEstablished(): void
    {
        $channel = $this->getReference('open_ai_channel');

        self::getContainer()->set(
            'oro_ai_content_generation.factory.test.open_ai_sdk_factory',
            new OpenAiSdkClientFactoryStub()
        );

        $this->ajaxRequest(
            'POST',
            $this->getUrl('oro_ai_content_generation_validate_connection', ['channelId' => $channel->getId()])
        );

        $result = $this->client->getResponse();

        self::assertJsonResponseStatusCodeEquals($result, 200);
        self::assertEquals(
            [
                'success' => true,
                'message' => 'Connection established successfully'
            ],
            json_decode($result->getContent(), true)
        );
    }

    public function testThatVertexAiClientConnectionEstablished(): void
    {
        $channel = $this->getReference('vertex_ai_channel');

        $this->ajaxRequest(
            'POST',
            $this->getUrl('oro_ai_content_generation_validate_connection', ['channelId' => $channel->getId()])
        );

        $result = $this->client->getResponse();

        self::assertJsonResponseStatusCodeEquals($result, 200);
        self::assertEquals(
            [
                'success' => true,
                'message' => 'Connection established successfully'
            ],
            json_decode($result->getContent(), true)
        );
    }

    /**
     * @dataProvider restrictedByFeatureRoutesDataProvider
     */
    public function testThatRoutesAreNotAvailableForDisabledFeature(string $restrictedRoute): void
    {
        $this->ajaxRequest('POST', $this->getUrl($restrictedRoute));
        self::assertResponseStatusCodeEquals($this->client->getResponse(), 404);
    }

    private function restrictedByFeatureRoutesDataProvider(): array
    {
        return [
            'render form' => [
                'oro_ai_content_generation_form'
            ],
            'update form' => [
                'oro_ai_content_generation_update'
            ],
            'form content' => [
                'oro_ai_content_generation_form_content'
            ]
        ];
    }
}
