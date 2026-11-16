<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

/**
 * Immutable result of one summary generation call.
 *
 * Carries the AI text, so it must never be passed into log context; only the model and the token counts
 * are logged.
 */
final readonly class SummaryResponse
{
    /**
     * @param string   $text         The generated summary text
     * @param string   $model        The model the provider reports having used
     * @param int|null $inputTokens  Prompt token usage, when the provider reports it
     * @param int|null $outputTokens Completion token usage, when the provider reports it
     */
    public function __construct(
        public string $text,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {
    }
}
