<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\RequestOptions;
use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a summary with one Vertex AI (Gemini) generateContent call.
 *
 * - The system prompt goes into `systemInstruction`, the facts block is the single user content, and the
 *   generation config carries the request's temperature and token budget plus the configured thinking budget.
 *   Model families without thinking support (gemini-1.*, gemini-2.0*) reject
 *   `thinkingConfig` outright, so it is sent only to gemini-2.5 and newer. No tools are sent.
 * - Only a `STOP` finish reason (or none) is a complete summary: `MAX_TOKENS` is truncated output and any other
 *   reason (safety, recitation, …) is a failure, never a partial paragraph.
 * - Every failure becomes a {@see SummaryClientException} whose message never carries the token, the prompt
 *   or the response body; the original exception is not attached.
 */
class VertexAiSummaryClient implements SummaryClientInterface
{
    public const string PROVIDER_TYPE = VertexAiChannel::TYPE;

    private const string GENERATE_CONTENT_SUFFIX = ':generateContent';
    private const string FINISH_REASON_STOP = 'STOP';
    private const string FINISH_REASON_MAX_TOKENS = 'MAX_TOKENS';
    private const int CHECK_CONNECTION_MAX_TOKENS = 16;
    private const string PROVIDER_ERROR_CODE_PATTERN = '/^[A-Z0-9_]{1,64}$/';
    private const string MODEL_NAME_PATTERN = '/^[A-Za-z0-9._:\/-]{1,128}$/';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $baseUri,
        private readonly string $accessToken,
        private readonly string $model,
        private readonly int $thinkingBudget
    ) {
    }

    #[\Override]
    public function generateSummary(SummaryRequest $request): SummaryResponse
    {
        $response = $this->generateContent([
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->userContent]]]],
            'generationConfig' => $this->buildGenerationConfig($request->maxOutputTokens, $request->temperature),
        ]);

        $candidate = $response['candidates'][0] ?? null;
        if (!\is_array($candidate)) {
            throw new SummaryClientException('Vertex AI returned no candidates.');
        }
        $finishReason = $candidate['finishReason'] ?? null;
        if (self::FINISH_REASON_MAX_TOKENS === $finishReason) {
            throw new SummaryClientException(
                'Vertex AI stopped the summary at the output token limit.',
                SummaryClientException::REASON_TRUNCATED
            );
        }
        if (null !== $finishReason && self::FINISH_REASON_STOP !== $finishReason) {
            throw new SummaryClientException(sprintf(
                'Vertex AI did not complete the summary (%s).',
                \is_string($finishReason) && preg_match(self::PROVIDER_ERROR_CODE_PATTERN, $finishReason)
                    ? $finishReason
                    : 'unknown reason'
            ));
        }

        $text = trim((string)($candidate['content']['parts'][0]['text'] ?? ''));
        if ('' === $text) {
            throw new SummaryClientException('Vertex AI returned an empty summary.');
        }

        return new SummaryResponse(
            $text,
            $this->getReportedModel($response['modelVersion'] ?? null),
            $this->getTokenCount($response, 'promptTokenCount'),
            $this->getTokenCount($response, 'candidatesTokenCount')
        );
    }

    /**
     * The provider's own model version is response data: only an identifier-looking value is reported,
     * otherwise the configured model stands in.
     */
    private function getReportedModel(mixed $model): string
    {
        return \is_string($model) && preg_match(self::MODEL_NAME_PATTERN, $model) ? $model : $this->model;
    }

    #[\Override]
    public function getProviderType(): string
    {
        return self::PROVIDER_TYPE;
    }

    #[\Override]
    public function checkConnection(): void
    {
        $this->generateContent([
            'contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
            'generationConfig' => $this->buildGenerationConfig(self::CHECK_CONNECTION_MAX_TOKENS, null),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function generateContent(array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseUri . self::GENERATE_CONTENT_SUFFIX, [
                RequestOptions::JSON => $body,
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (NetworkExceptionInterface) {
            throw new SummaryClientException(
                'Vertex AI request timed out or the connection failed.',
                SummaryClientException::REASON_TIMEOUT
            );
        } catch (BadResponseException $exception) {
            throw $this->createResponseException($exception->getResponse());
        } catch (\Throwable $exception) {
            throw new SummaryClientException(sprintf('Vertex AI request failed: %s.', $exception::class));
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGenerationConfig(int $maxOutputTokens, ?float $temperature): array
    {
        $config = ['maxOutputTokens' => $maxOutputTokens];
        if (null !== $temperature) {
            $config['temperature'] = $temperature;
        }
        if ($this->supportsThinkingConfig()) {
            $config['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }

        return $config;
    }

    /**
     * Thinking arrived with Gemini 2.5; earlier families reject the setting with HTTP 400.
     */
    private function supportsThinkingConfig(): bool
    {
        return !str_starts_with($this->model, 'gemini-1.') && !str_starts_with($this->model, 'gemini-2.0');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SummaryClientException('Vertex AI returned a response that is not JSON.');
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function getTokenCount(array $response, string $field): ?int
    {
        $count = $response['usageMetadata'][$field] ?? null;

        return \is_int($count) ? $count : null;
    }

    private function createResponseException(ResponseInterface $response): SummaryClientException
    {
        $httpStatus = $response->getStatusCode();
        $providerErrorCode = $this->getProviderErrorCode($response);
        $message = sprintf(
            'Vertex AI request failed with HTTP %d%s.',
            $httpStatus,
            null !== $providerErrorCode ? sprintf(' (%s)', $providerErrorCode) : ''
        );

        if (Response::HTTP_TOO_MANY_REQUESTS === $httpStatus) {
            return new SummaryClientException(
                $message,
                SummaryClientException::REASON_RATE_LIMITED,
                $httpStatus,
                $providerErrorCode
            );
        }

        return new SummaryClientException(
            $message,
            SummaryClientException::REASON_CLIENT_ERROR,
            $httpStatus,
            $providerErrorCode
        );
    }

    /**
     * The Vertex `error.status` (e.g. PERMISSION_DENIED) when it looks like an enumerated code; never a message.
     */
    private function getProviderErrorCode(ResponseInterface $response): ?string
    {
        $decoded = json_decode((string)$response->getBody(), true);
        $status = $decoded['error']['status'] ?? null;

        return \is_string($status) && preg_match(self::PROVIDER_ERROR_CODE_PATTERN, $status) ? $status : null;
    }
}
