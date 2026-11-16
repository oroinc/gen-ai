<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use OpenAI\Factory;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\Responses\Fixtures\Chat\CreateResponseFixture;
use Oro\Bundle\SmartBriefBundle\Client\OpenAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the OpenAI client: the payload per model family, response mapping, error reasons and the connection check.
 */
final class OpenAiSummaryClientTest extends TestCase
{
    private const string TOKEN = 'sk-secret-token';
    private const string SYSTEM_PROMPT = 'You summarize facts.';
    private const string USER_CONTENT = "<facts>\nsecret-fact\n</facts>";

    private ChatContract&MockObject $chat;

    #[\Override]
    protected function setUp(): void
    {
        $this->chat = $this->createMock(ChatContract::class);
    }

    /**
     * @dataProvider legacyModelDataProvider
     */
    public function testLegacyFamilyPayloadAndResponseMapping(string $model): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->with([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => self::USER_CONTENT],
                ],
                'max_tokens' => 1024,
                'temperature' => 0.2,
            ])
            ->willReturn(CreateResponse::fake([
                'model' => 'gpt-4o-mini-2024-07-18',
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => ' Summary text. ']]],
                'usage' => ['prompt_tokens' => 812, 'completion_tokens' => 64, 'total_tokens' => 876],
            ]));

        $response = $this->createClient($model)->generateSummary($this->createRequest());

        self::assertSame('Summary text.', $response->text);
        self::assertSame('gpt-4o-mini-2024-07-18', $response->model);
        self::assertSame(812, $response->inputTokens);
        self::assertSame(64, $response->outputTokens);
    }

    public static function legacyModelDataProvider(): array
    {
        return [
            'gpt-3.5-turbo' => ['gpt-3.5-turbo'],
            'gpt-4o-mini' => ['gpt-4o-mini'],
            'gpt-4.1 dated' => ['gpt-4.1-2025-04-14'],
        ];
    }

    /**
     * @dataProvider reasoningModelDataProvider
     */
    public function testReasoningFamilyPayload(string $model, string $reasoningEffort): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->with([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => self::USER_CONTENT],
                ],
                'max_completion_tokens' => 1024,
                'reasoning_effort' => $reasoningEffort,
            ])
            ->willReturn($this->createResponse([
                'model' => 'gpt-5-mini-2025-08-07',
                'choices' => [
                    [
                        'index' => 0,
                        'finish_reason' => 'stop',
                        'message' => ['role' => 'assistant', 'content' => 'Summary.'],
                    ],
                ],
                'usage' => ['prompt_tokens' => 812, 'total_tokens' => 812],
            ]));

        $response = $this->createClient($model)->generateSummary($this->createRequest());

        self::assertSame('Summary.', $response->text);
        self::assertSame('gpt-5-mini-2025-08-07', $response->model);
        self::assertSame(812, $response->inputTokens);
        self::assertNull($response->outputTokens);
    }

    public static function reasoningModelDataProvider(): array
    {
        return [
            'gpt-5' => ['gpt-5', 'minimal'],
            'gpt-5-mini' => ['gpt-5-mini', 'minimal'],
            'gpt-5-nano dated' => ['gpt-5-nano-2025-08-07', 'minimal'],
            'o3' => ['o3', 'low'],
            'gpt-5.1' => ['gpt-5.1', 'low'],
            'gpt-5.4-mini dated' => ['gpt-5.4-mini-2026-03-17', 'low'],
            'gpt-6-sol' => ['gpt-6-sol', 'low'],
        ];
    }

    public function testGetProviderType(): void
    {
        self::assertSame('open_ai', $this->createClient('gpt-5-mini')->getProviderType());
    }

    public function testTruncatedOutputThrowsWithoutSecondRequest(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willReturn(CreateResponse::fake([
                'choices' => [['finish_reason' => 'length', 'message' => ['content' => 'Partial']]],
            ]));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_TRUNCATED, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
    }

    public function testEmptyContentThrows(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willReturn(CreateResponse::fake([
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '   ']]],
            ]));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
    }

    public function testNoChoicesThrows(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willReturn($this->createResponse(['choices' => []]));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
    }

    public function testRateLimited(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willThrowException(new ErrorException(
                [
                    'message' => 'Rate limit reached for ' . self::TOKEN,
                    'type' => 'requests',
                    'code' => 'rate_limit_exceeded',
                ],
                429
            ));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_RATE_LIMITED, $exception->getReason());
        self::assertSame(429, $exception->getHttpStatus());
        self::assertSame('rate_limit_exceeded', $exception->getProviderErrorCode());
    }

    public function testApiErrorBecomesClientErrorWithStatusAndCode(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willThrowException(new ErrorException(
                [
                    'message' => 'Incorrect API key provided: ' . self::TOKEN,
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
                401
            ));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertSame(401, $exception->getHttpStatus());
        self::assertSame('invalid_api_key', $exception->getProviderErrorCode());
    }

    public function testTimeoutBecomesTimeoutReason(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willThrowException(new TransporterException(new ConnectException(
                'cURL error 28: Operation timed out after 10001 milliseconds',
                new Request('POST', 'https://api.openai.com/v1/chat/completions')
            )));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_TIMEOUT, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
    }

    public function testNonJsonErrorBodyBecomesClientErrorWithoutStatus(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willThrowException(new UnserializableResponse(new \JsonException('Syntax error')));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
        self::assertNull($exception->getProviderErrorCode());
    }

    public function testRateLimitThroughRealSdkTransporter(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                429,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => [
                    'message' => 'Rate limit reached for ' . self::TOKEN,
                    'type' => 'requests',
                    'code' => 'rate_limit_exceeded',
                ]])
            ),
        ]);
        $httpClient = new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]);
        $sdkClient = (new Factory())->withApiKey(self::TOKEN)->withHttpClient($httpClient)->make();
        $client = new OpenAiSummaryClient($sdkClient, 'gpt-5-mini', 'minimal');

        $exception = $this->expectSummaryClientException(fn () => $client->generateSummary($this->createRequest()));

        self::assertSame(SummaryClientException::REASON_RATE_LIMITED, $exception->getReason());
        self::assertSame(429, $exception->getHttpStatus());
        self::assertSame('rate_limit_exceeded', $exception->getProviderErrorCode());
        self::assertSame(0, $mockHandler->count());
    }

    public function testFreeTextOrMissingErrorCodeIsDropped(): void
    {
        $this->chat->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new ErrorException(['message' => 'x', 'type' => null, 'code' => null], 500)),
                self::throwException(new ErrorException(
                    ['message' => 'x', 'type' => null, 'code' => 'Incorrect API key provided: ' . self::TOKEN],
                    401
                ))
            );
        $client = $this->createClient('gpt-5-mini');

        $first = $this->expectSummaryClientException(fn () => $client->generateSummary($this->createRequest()));
        $second = $this->expectSummaryClientException(fn () => $client->generateSummary($this->createRequest()));

        self::assertSame(500, $first->getHttpStatus());
        self::assertNull($first->getProviderErrorCode());
        self::assertSame(401, $second->getHttpStatus());
        self::assertNull($second->getProviderErrorCode());
    }

    public function testUnexpectedExceptionBecomesClientError(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willThrowException(new \RuntimeException('Boom with ' . self::TOKEN));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient('gpt-5-mini')->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
    }

    public function testCheckConnectionSendsOneMinimalRequest(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->with([
                'model' => 'gpt-5-mini',
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_completion_tokens' => 64,
                'reasoning_effort' => 'minimal',
            ])
            ->willReturn(CreateResponse::fake([
                'choices' => [['finish_reason' => 'length', 'message' => ['content' => '']]],
            ]));

        $this->createClient('gpt-5-mini')->checkConnection();
    }

    public function testCheckConnectionFailureThrows(): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->with([
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 64,
            ])
            ->willThrowException(
                new ErrorException(['message' => 'bad key', 'type' => null, 'code' => 'invalid_api_key'], 401)
            );

        $exception = $this->expectSummaryClientException(fn () => $this->createClient('gpt-4o')->checkConnection());

        self::assertSame(401, $exception->getHttpStatus());
    }

    private function createClient(string $model): OpenAiSummaryClient
    {
        $sdkClient = $this->createMock(ClientContract::class);
        $sdkClient->expects(self::any())
            ->method('chat')
            ->willReturn($this->chat);

        return new OpenAiSummaryClient($sdkClient, $model, 'low');
    }

    /**
     * Builds a response without the fixture merge of {@see CreateResponse::fake()},
     * so empty and missing values stay as given.
     */
    private function createResponse(array $overrides): CreateResponse
    {
        return CreateResponse::from(
            array_replace(CreateResponseFixture::ATTRIBUTES, $overrides),
            CreateResponse::fakeResponseMetaInformation()
        );
    }

    /**
     * @dataProvider hostileModelDataProvider
     */
    public function testReportedModelIsBoundedToAnIdentifier(mixed $reported, string $expected): void
    {
        $this->chat->expects(self::once())
            ->method('create')
            ->willReturn(CreateResponse::fake([
                'model' => $reported,
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Summary.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]));

        $response = $this->createClient('gpt-5-mini')->generateSummary($this->createRequest());

        self::assertSame($expected, $response->model);
    }

    public static function hostileModelDataProvider(): array
    {
        return [
            'identifier' => ['gpt-5-mini-2026-01-01', 'gpt-5-mini-2026-01-01'],
            'echoes the prompt' => ['<facts> Acme ordered twice </facts>', 'gpt-5-mini'],
            'too long' => [str_repeat('a', 129), 'gpt-5-mini'],
            'empty' => ['', 'gpt-5-mini'],
        ];
    }

    private function createRequest(): SummaryRequest
    {
        return new SummaryRequest(self::SYSTEM_PROMPT, self::USER_CONTENT, 'en', 0.2, 1024);
    }

    /**
     * Runs the callable, asserts a SummaryClientException is thrown and that it leaks neither the token,
     * the prompt, the facts nor the original exception.
     */
    private function expectSummaryClientException(callable $callable): SummaryClientException
    {
        try {
            $callable();
        } catch (SummaryClientException $exception) {
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString('secret-fact', $exception->getMessage());
            self::assertStringNotContainsString(self::SYSTEM_PROMPT, $exception->getMessage());
            self::assertNull($exception->getPrevious());

            return $exception;
        }

        self::fail('Expected SummaryClientException.');
    }
}
