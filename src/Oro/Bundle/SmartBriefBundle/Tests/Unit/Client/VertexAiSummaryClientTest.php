<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use Oro\Bundle\SmartBriefBundle\Client\VertexAiSummaryClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Covers the Vertex AI client (payload, response mapping, error reasons, the connection check),
 * through a real Guzzle client with a mock handler.
 */
final class VertexAiSummaryClientTest extends TestCase
{
    private const string BASE_URI = 'https://us-central1-aiplatform.googleapis.com/v1/projects/p1/locations/us-central1'
        . '/publishers/google/models/gemini-2.5-flash';
    private const string TOKEN = 'ya29.secret-token';
    private const string SYSTEM_PROMPT = 'You summarize facts.';
    private const string USER_CONTENT = "<facts>\nsecret-fact\n</facts>";

    private MockHandler $mockHandler;
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
    }

    public function testGenerateSummarySendsTheExpectedBodyAndMapsTheResponse(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => ' Summary. ']]], 'finishReason' => 'STOP']],
            'modelVersion' => 'gemini-2.5-flash-001',
            'usageMetadata' => ['promptTokenCount' => 790, 'candidatesTokenCount' => 58],
        ])));

        $response = $this->createClient(0)->generateSummary($this->createRequest());

        self::assertSame('Summary.', $response->text);
        self::assertSame('gemini-2.5-flash-001', $response->model);
        self::assertSame(790, $response->inputTokens);
        self::assertSame(58, $response->outputTokens);

        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URI . ':generateContent', (string)$request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(
            [
                'systemInstruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => self::USER_CONTENT]]]],
                'generationConfig' => [
                    'maxOutputTokens' => 1024,
                    'temperature' => 0.2,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ],
            json_decode((string)$request->getBody(), true)
        );
    }

    public function testThinkingBudgetAndMissingMetadata(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Summary.']]], 'finishReason' => 'STOP']],
        ])));

        $response = $this->createClient(128)->generateSummary($this->createRequest());

        self::assertSame('gemini-2.5-flash', $response->model, 'falls back to the configured model');
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        self::assertSame(128, $body['generationConfig']['thinkingConfig']['thinkingBudget']);
        self::assertArrayNotHasKey('tools', $body);
    }

    /**
     * @dataProvider thinkingConfigDataProvider
     */
    public function testThinkingConfigIsSentOnlyToThinkingCapableFamilies(string $model, bool $expected): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Summary.']]], 'finishReason' => 'STOP']],
        ])));

        $this->createClient(128, $model)->generateSummary($this->createRequest());

        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        self::assertSame($expected, isset($body['generationConfig']['thinkingConfig']));
    }

    public static function thinkingConfigDataProvider(): array
    {
        return [
            'gemini-1.5-pro' => ['gemini-1.5-pro', false],
            'gemini-2.0-flash' => ['gemini-2.0-flash', false],
            'gemini-2.5-flash' => ['gemini-2.5-flash', true],
            'gemini-3-pro' => ['gemini-3-pro-preview', true],
        ];
    }

    /**
     * @dataProvider blockedFinishReasonDataProvider
     */
    public function testNonStopFinishReasonIsNeverASummary(string $finishReason, string $expectedInMessage): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Partial text']]], 'finishReason' => $finishReason]],
        ])));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertStringContainsString($expectedInMessage, $exception->getMessage());
    }

    public static function blockedFinishReasonDataProvider(): array
    {
        return [
            'safety' => ['SAFETY', 'SAFETY'],
            'recitation' => ['RECITATION', 'RECITATION'],
            'free text is not echoed' => ['blocked: ' . self::TOKEN, 'unknown reason'],
        ];
    }

    public function testGetProviderType(): void
    {
        self::assertSame('vertex_ai', $this->createClient(0)->getProviderType());
    }

    public function testTruncatedOutputThrowsWithoutSecondRequest(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Partial']]], 'finishReason' => 'MAX_TOKENS']],
        ])));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_TRUNCATED, $exception->getReason());
        self::assertCount(1, $this->history);
    }

    /**
     * @dataProvider unusableResponseDataProvider
     */
    public function testUnusableSuccessfulResponseThrows(string $body): void
    {
        $this->mockHandler->append(new Response(200, [], $body));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
    }

    public static function unusableResponseDataProvider(): array
    {
        return [
            'no candidates' => ['{"candidates":[]}'],
            'missing text' => ['{"candidates":[{"content":{"parts":[{}]},"finishReason":"STOP"}]}'],
            'empty text' => ['{"candidates":[{"content":{"parts":[{"text":"  "}]},"finishReason":"STOP"}]}'],
            'not json' => ['<html>secret-fact</html>'],
        ];
    }

    public function testRateLimited(): void
    {
        $this->mockHandler->append(new Response(
            429,
            [],
            json_encode([
                'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota ' . self::TOKEN],
            ])
        ));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_RATE_LIMITED, $exception->getReason());
        self::assertSame(429, $exception->getHttpStatus());
        self::assertSame('RESOURCE_EXHAUSTED', $exception->getProviderErrorCode());
    }

    public function testPermissionDeniedBecomesClientErrorWithStatusAndCode(): void
    {
        $this->mockHandler->append(new Response(
            403,
            [],
            json_encode([
                'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Denied for ' . self::TOKEN],
            ])
        ));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertSame(403, $exception->getHttpStatus());
        self::assertSame('PERMISSION_DENIED', $exception->getProviderErrorCode());
    }

    public function testNonJsonServerErrorKeepsStatusWithoutCode(): void
    {
        $this->mockHandler->append(
            new Response(502, ['Content-Type' => 'text/html'], '<html>' . self::TOKEN . '</html>')
        );

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertSame(502, $exception->getHttpStatus());
        self::assertNull($exception->getProviderErrorCode());
    }

    public function testTimeoutBecomesTimeoutReason(): void
    {
        $this->mockHandler->append(new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', self::BASE_URI . ':generateContent')
        ));

        $exception = $this->expectSummaryClientException(
            fn () => $this->createClient(0)->generateSummary($this->createRequest())
        );

        self::assertSame(SummaryClientException::REASON_TIMEOUT, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
    }

    public function testCheckConnectionSendsOneMinimalRequest(): void
    {
        $this->mockHandler->append(new Response(200, [], '{"candidates":[]}'));

        $this->createClient(0)->checkConnection();

        self::assertCount(1, $this->history);
        self::assertSame(
            [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
                'generationConfig' => ['maxOutputTokens' => 16, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ],
            json_decode((string)$this->history[0]['request']->getBody(), true)
        );
    }

    public function testCheckConnectionFailureThrows(): void
    {
        $this->mockHandler->append(new Response(401, [], '{"error":{"status":"UNAUTHENTICATED"}}'));

        $exception = $this->expectSummaryClientException(fn () => $this->createClient(0)->checkConnection());

        self::assertSame(401, $exception->getHttpStatus());
        self::assertSame('UNAUTHENTICATED', $exception->getProviderErrorCode());
    }

    /**
     * @dataProvider hostileModelDataProvider
     */
    public function testReportedModelIsBoundedToAnIdentifier(mixed $reported, string $expected): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Summary.']]], 'finishReason' => 'STOP']],
            'modelVersion' => $reported,
        ])));

        $response = $this->createClient(0)->generateSummary($this->createRequest());

        self::assertSame($expected, $response->model);
    }

    public static function hostileModelDataProvider(): array
    {
        return [
            'identifier' => ['gemini-2.5-flash-001', 'gemini-2.5-flash-001'],
            'echoes the prompt' => ['<facts> Acme ordered twice </facts>', 'gemini-2.5-flash'],
            'too long' => [str_repeat('a', 129), 'gemini-2.5-flash'],
            'not a string' => [['nested'], 'gemini-2.5-flash'],
            'missing' => [null, 'gemini-2.5-flash'],
        ];
    }

    private function createClient(int $thinkingBudget, string $model = 'gemini-2.5-flash'): VertexAiSummaryClient
    {
        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->history));
        $httpClient = new GuzzleClient(['handler' => $stack]);

        return new VertexAiSummaryClient($httpClient, self::BASE_URI, self::TOKEN, $model, $thinkingBudget);
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
