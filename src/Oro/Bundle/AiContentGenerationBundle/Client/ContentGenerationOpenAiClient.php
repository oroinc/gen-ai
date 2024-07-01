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

    #[\Override] public function generateTextContent(ContentGenerationRequest $request): string
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

    #[\Override] public function checkConnection(): void
    {
        $this->executedRequests = 0;

        $this->doRequest([OpenAiMessage::fromSystem('Check connection')]);
    }

    #[\Override] public function supportsUserContentSize(): bool
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
            return $this->openAiSdkClient->chat()->create([
                'model' => $this->parameterBag->get(OpenAiTransportSettings::MODEL),
                'messages' => array_map(fn (OpenAiMessage $message) => $message->toArray(), $messages),
                'max_tokens' => $this->parameterBag->get('maxTokens') * $this->executedRequests,
                'temperature' => 1,
                'top_p' => 1,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
                ...$this->parameterBag->get('additionalParameters', [])
            ]);
        } catch (ErrorException $exception) {
            throw new ContentGenerationClientException($exception->getMessage());
        }
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
