<?php

namespace Oro\Bundle\AiContentGenerationBundle\Client;

use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Responses\Chat\CreateResponseChoice;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Represents OpenAI Client
 */
class ContentGenerationOpenAiClient implements ContentGenerationClientInterface
{
    public const string OPEN_AI = 'open_ai';

    private int $executedRequests = 0;

    public function __construct(
        private readonly ClientContract $openAiSdkClient,
        private readonly ParameterBag $parameterBag
    ) {
    }

    #[\Override]
    public function generateTextContent(ContentGenerationRequest $request): string
    {
        $this->executedRequests = 0;

        $messages = [
            OpenAiMessage::fromSystem($request->getClientPrompt()),
            OpenAiMessage::fromUser($request->getClientContext())
        ];

        try {
            for ($i = 0; $i < $this->parameterBag->get('maxIterations'); $i++) {
                $response = $this->doRequest($messages);
                /** @var CreateResponseChoice $choiceResponse */
                $choiceResponse = $response->choices[0];

                $messages[] = OpenAiMessage::fromAssistant($choiceResponse->message->content);
                if (!$this->isStoppedByLengthLimit($choiceResponse)) {
                    return $this->getResult($messages);
                }

                $messages[] = OpenAiMessage::fromSystem('Continue');
            }
        } catch (\Throwable $exception) {
            throw new ContentGenerationClientException(
                $exception->getMessage()
            );
        }

        return $this->getResult($messages);
    }

    #[\Override]
    public function checkConnection(): void
    {
        $this->executedRequests = 0;

        $this->doRequest([OpenAiMessage::fromSystem('Check connection')]);
    }

    #[\Override]
    public function supportsUserContentSize(): bool
    {
        return false;
    }

    /**
     * @param array<int, OpenAiMessage> $messages
     */
    private function doRequest(array $messages): CreateResponse
    {
        $this->executedRequests++;

        try {
            return $this->openAiSdkClient->chat()->create($this->buildPayload($messages));
        } catch (ErrorException $exception) {
            throw new ContentGenerationClientException($exception->getMessage());
        }
    }

    /**
     * @param array<int, OpenAiMessage> $messages
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages): array
    {
        $model = $this->parameterBag->get(OpenAiTransportSettings::MODEL);
        // gpt-3 and gpt-4 are closed families (OpenAI no longer ships new models there) and use max_tokens;
        // every later family (o-series, gpt-5+, and anything future) uses max_completion_tokens.
        $maxTokensField = str_starts_with($model, 'gpt-3.') || str_starts_with($model, 'gpt-4')
            ? 'max_tokens'
            : 'max_completion_tokens';

        return [
            'model' => $model,
            'messages' => array_map(fn (OpenAiMessage $message) => $message->toArray(), $messages),
            $maxTokensField => $this->parameterBag->get('maxTokens') * $this->executedRequests,
            'temperature' => 1,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
            ...$this->parameterBag->get('additionalParameters', [])
        ];
    }

    /**
     * @param OpenAiMessage[] $messages
     */
    private function getResult(array $messages): string
    {
        $contentMessages = array_filter($messages, fn (OpenAiMessage $message) => $message->isAssistant());

        return rtrim(
            array_reduce(
                $contentMessages,
                fn ($carry, OpenAiMessage $message) => $carry . $message->getContent() . ' ',
                ''
            )
        );
    }

    private function isStoppedByLengthLimit(CreateResponseChoice $choice): bool
    {
        return $choice->finishReason === 'length';
    }
}
