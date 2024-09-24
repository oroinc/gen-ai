<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Client;

use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Responses\Chat\CreateResponse;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationOpenAiClient;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class ContentGenerationOpenAiClientTest extends TestCase
{
    private ContentGenerationOpenAiClient $oroClient;

    private ClientContract&MockObject $openAiClient;

    private ChatContract&MockObject $chatContract;

    private ContentGenerationRequest $request;

    private ParameterBag $parameters;

    #[\Override]
    protected function setUp(): void
    {
        $this->openAiClient = $this->createMock(ClientContract::class);
        $this->chatContract = $this->createMock(ChatContract::class);

        $this->openAiClient
            ->expects(self::any())
            ->method('chat')
            ->willReturn($this->chatContract);

        $this->request = new ContentGenerationRequest(
            'Simplify',
            ['Value' => 'Long complex text'],
            'Normal',
            1000,
        );

        $this->parameters = new ParameterBag([
            'model' => 'modelName',
            'maxTokens' => 1000,
            'maxIterations' => 2,
            'additionalParameters' => [
                'temperature' => 2
            ]
        ]);

        $this->oroClient = new ContentGenerationOpenAiClient($this->openAiClient, $this->parameters);
    }

    public function testSupportsUserContentSize(): void
    {
        self::assertFalse($this->oroClient->supportsUserContentSize());
    }

    public function testThatFullResultReturnedFromFirstTry(): void
    {
        $fakeResponse = CreateResponse::fake([
            'choices' => [
                ['message' => ['content' => 'simplified generated text']]
            ]
        ]);

        $this->chatContract
            ->expects(self::once())
            ->method('create')
            ->with([
                'model' => 'modelName',
                'messages' => [
                    ['role' => 'system', 'content' => $this->request->getClientPrompt()],
                    ['role' => 'user', 'content' => $this->request->getClientContext()]
                ],
                'max_tokens' => 1000,
                'temperature' => 2,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
            ])
            ->willReturn($fakeResponse);

        self::assertEquals(
            'simplified generated text',
            $this->oroClient->generateTextContent($this->request)
        );
    }

    public function testThatFullResultReturnedAfterAllTries(): void
    {
        $fakeResponse1 = CreateResponse::fake([
            'choices' => [
                ['finish_reason' => 'length', 'message' => ['content' => 'simplified generated text']],
            ]
        ]);

        $fakeResponse2 = CreateResponse::fake([
            'choices' => [
                ['message' => ['content' => 'finished with 2 tries']],
            ]
        ]);

        $this->chatContract
            ->expects(self::any())
            ->method('create')
            ->willReturnOnConsecutiveCalls($fakeResponse1, $fakeResponse2);

        self::assertEquals(
            'simplified generated text finished with 2 tries',
            $this->oroClient->generateTextContent($this->request)
        );
    }

    public function testThatNotFullResultReturned(): void
    {
        $fakeResponse1 = CreateResponse::fake([
            'choices' => [
                ['finish_reason' => 'length', 'message' => ['content' => 'simplified generated text']],
            ]
        ]);

        $fakeResponse2 = CreateResponse::fake([
            'choices' => [
                ['finish_reason' => 'length', 'message' => ['content' => 'unfinished']],
            ]
        ]);

        $this->chatContract
            ->expects(self::any())
            ->method('create')
            ->willReturnOnConsecutiveCalls($fakeResponse1, $fakeResponse2);

        self::assertEquals(
            'simplified generated text unfinished',
            $this->oroClient->generateTextContent($this->request)
        );
    }

    public function testThatHandledNotExpectedException(): void
    {
        $this->chatContract
            ->expects(self::once())
            ->method('create')
            ->willThrowException(new \Exception('OpenAI exception'));

        self::expectException(ContentGenerationClientException::class);
        self::expectExceptionMessage('OpenAI exception');

        $this->oroClient->generateTextContent($this->request);
    }

    public function testSuccessfulCheckConnection(): void
    {
        $this->chatContract
            ->expects(self::any())
            ->method('create')
            ->willReturn(CreateResponse::fake());

        $this->oroClient->checkConnection();
    }

    public function testFailedCheckConnection(): void
    {
        $this->chatContract
            ->expects(self::any())
            ->method('create')
            ->willThrowException(new ErrorException(['message' => 'Message']));

        self::expectException(ContentGenerationClientException::class);

        $this->oroClient->checkConnection();
    }
}
