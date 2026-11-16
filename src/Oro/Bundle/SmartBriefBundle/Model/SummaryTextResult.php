<?php

namespace Oro\Bundle\SmartBriefBundle\Model;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;

/**
 * The outcome of one AI call for a summary: the tidied paragraph with what the provider reported about it, or
 * the reason the AI could not be used (a fallback). It carries everything the metrics entry needs
 * and never the prompt, the raw response or an exception message.
 */
final readonly class SummaryTextResult
{
    private function __construct(
        public ?string $text,
        public ?string $channelType,
        public ?string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?string $reason,
        public ?int $httpStatus,
        public ?string $providerErrorCode
    ) {
    }

    public static function success(
        string $text,
        string $channelType,
        string $model,
        ?int $inputTokens,
        ?int $outputTokens
    ): self {
        return new self($text, $channelType, $model, $inputTokens, $outputTokens, null, null, null);
    }

    /**
     * @param string|null $channelType null when the client could not even be built
     */
    public static function fallback(SummaryClientException $exception, ?string $channelType): self
    {
        return new self(
            null,
            $channelType,
            null,
            null,
            null,
            $exception->getReason(),
            $exception->getHttpStatus(),
            $exception->getProviderErrorCode()
        );
    }

    public function isFallback(): bool
    {
        return null === $this->text;
    }
}
