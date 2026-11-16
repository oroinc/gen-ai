<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Chat\CreateResponseChoice;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use Psr\Http\Client\NetworkExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a summary with one OpenAI chat completion.
 *
 * - Legacy chat families (gpt-3.*, gpt-4*) get `max_tokens` and the request temperature; reasoning-capable
 *   families (gpt-5*, o-series and anything newer) get `max_completion_tokens` and `reasoning_effort`
 *   and no temperature, because they reject values other than 1.
 * - The first gpt-5 family (gpt-5, gpt-5-mini, gpt-5-nano) gets `minimal` reasoning effort: it is too slow
 *   for the timeout at `low`. Later families reject `minimal`, so they get the configured effort.
 * - Every failure becomes a {@see SummaryClientException} whose message never carries the token, the prompt
 *   or the response body; the original exception is not attached.
 * - The SDK raises its typed error, with the HTTP status, only for JSON error bodies; anything else (an HTML 502
 *   from a gateway) is a client error without a status.
 */
class OpenAiSummaryClient implements SummaryClientInterface
{
    public const string PROVIDER_TYPE = OpenAiChannel::TYPE;

    private const string FINISH_REASON_LENGTH = 'length';
    // A reasoning model fails with HTTP 400 when its reasoning alone uses up the budget, which happens at 16.
    private const int CHECK_CONNECTION_MAX_TOKENS = 64;
    private const string MINIMAL_REASONING_EFFORT = 'minimal';
    private const string PROVIDER_ERROR_CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const string MODEL_NAME_PATTERN = '/^[A-Za-z0-9._:\/-]{1,128}$/';

    public function __construct(
        private readonly ClientContract $sdkClient,
        private readonly string $model,
        private readonly string $reasoningEffort
    ) {
    }

    #[\Override]
    public function generateSummary(SummaryRequest $request): SummaryResponse
    {
        $response = $this->createChatCompletion(
            [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userContent],
            ],
            $request->maxOutputTokens,
            $request->temperature
        );

        /** @var CreateResponseChoice|null $choice */
        $choice = $response->choices[0] ?? null;
        if (null === $choice) {
            throw new SummaryClientException('OpenAI returned no choices.');
        }
        if (self::FINISH_REASON_LENGTH === $choice->finishReason) {
            throw new SummaryClientException(
                'OpenAI stopped the summary at the output token limit.',
                SummaryClientException::REASON_TRUNCATED
            );
        }

        $text = trim((string)$choice->message->content);
        if ('' === $text) {
            throw new SummaryClientException('OpenAI returned an empty summary.');
        }

        return new SummaryResponse(
            $text,
            $this->getReportedModel($response->model),
            $response->usage->promptTokens,
            $response->usage->completionTokens
        );
    }

    #[\Override]
    public function getProviderType(): string
    {
        return self::PROVIDER_TYPE;
    }

    #[\Override]
    public function checkConnection(): void
    {
        $this->createChatCompletion(
            [['role' => 'user', 'content' => 'ping']],
            self::CHECK_CONNECTION_MAX_TOKENS,
            null
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function createChatCompletion(array $messages, int $maxOutputTokens, ?float $temperature): CreateResponse
    {
        try {
            return $this->sdkClient->chat()->create($this->buildPayload($messages, $maxOutputTokens, $temperature));
        } catch (ErrorException $exception) {
            throw $this->createException($exception->getStatusCode(), (string)$exception->getErrorCode());
        } catch (TransporterException $exception) {
            if ($exception->getPrevious() instanceof NetworkExceptionInterface) {
                throw new SummaryClientException(
                    'OpenAI request timed out or the connection failed.',
                    SummaryClientException::REASON_TIMEOUT
                );
            }

            throw new SummaryClientException('OpenAI request failed at transport level.');
        } catch (\Throwable $exception) {
            throw new SummaryClientException(sprintf('OpenAI request failed: %s.', $exception::class));
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, int $maxOutputTokens, ?float $temperature): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        // Copied from Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationOpenAiClient::buildPayload:
        // gpt-3 and gpt-4 are closed families (OpenAI no longer ships new models there) and use max_tokens;
        // every later family (o-series, gpt-5+, and anything future) uses max_completion_tokens.
        if (str_starts_with($this->model, 'gpt-3.') || str_starts_with($this->model, 'gpt-4')) {
            $payload['max_tokens'] = $maxOutputTokens;
            if (null !== $temperature) {
                $payload['temperature'] = $temperature;
            }
        } else {
            $payload['max_completion_tokens'] = $maxOutputTokens;
            $payload['reasoning_effort'] = preg_match('/^gpt-5(-|$)/', $this->model)
                ? self::MINIMAL_REASONING_EFFORT
                : $this->reasoningEffort;
        }

        return $payload;
    }

    /**
     * The provider's own model name is response data: only an identifier-looking value is reported,
     * otherwise the configured model stands in.
     */
    private function getReportedModel(mixed $model): string
    {
        return \is_string($model) && preg_match(self::MODEL_NAME_PATTERN, $model) ? $model : $this->model;
    }

    private function createException(int $httpStatus, ?string $providerErrorCode): SummaryClientException
    {
        // Only an enumerated snake_case code may enter the message; anything else is dropped.
        if (null === $providerErrorCode || !preg_match(self::PROVIDER_ERROR_CODE_PATTERN, $providerErrorCode)) {
            $providerErrorCode = null;
        }
        $message = sprintf(
            'OpenAI request failed with HTTP %d%s.',
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
}
