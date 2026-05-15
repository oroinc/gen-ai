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
            'model' => 'gpt-4o',
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

    /**
     * @dataProvider maxTokensFieldProvider
     */
    public function testThatFullResultReturnedFromFirstTry(string $model, string $expectedField): void
    {
        $this->parameters->set('model', $model);

        $fakeResponse = CreateResponse::fake([
            'choices' => [
                ['message' => ['content' => 'simplified generated text']]
            ]
        ]);

        $this->chatContract
            ->expects(self::once())
            ->method('create')
            ->with([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->request->getClientPrompt()],
                    ['role' => 'user', 'content' => $this->request->getClientContext()]
                ],
                $expectedField => 1000,
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

    public function maxTokensFieldProvider(): array
    {
        return [
            // Closed legacy families that keep using max_tokens.
            'gpt-3.5-turbo' => ['gpt-3.5-turbo', 'max_tokens'],
            'gpt-3.5-turbo dated' => ['gpt-3.5-turbo-0125', 'max_tokens'],
            'gpt-4' => ['gpt-4', 'max_tokens'],
            'gpt-4-turbo' => ['gpt-4-turbo', 'max_tokens'],
            'gpt-4-turbo dated' => ['gpt-4-turbo-2024-04-09', 'max_tokens'],
            'gpt-4.1' => ['gpt-4.1', 'max_tokens'],
            'gpt-4o' => ['gpt-4o', 'max_tokens'],
            'gpt-4o-mini dated' => ['gpt-4o-mini-2024-07-18', 'max_tokens'],
            // Modern reasoning and gpt-5+ families plus any future generation.
            'o1-preview' => ['o1-preview', 'max_completion_tokens'],
            'o3-mini' => ['o3-mini', 'max_completion_tokens'],
            'o4-mini dated' => ['o4-mini-2025-04-16', 'max_completion_tokens'],
            'gpt-5' => ['gpt-5', 'max_completion_tokens'],
            'gpt-5.4-mini dated' => ['gpt-5.4-mini-2026-03-17', 'max_completion_tokens'],
            'hypothetical gpt-6' => ['gpt-6', 'max_completion_tokens'],
        ];
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
