<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

/**
 * Immutable input of one summary generation call.
 *
 * Carries the prompt and the facts, so it must never be passed into log context or serialized into logs.
 */
final readonly class SummaryRequest
{
    /**
     * @param string $systemPrompt    Instructions for the model
     * @param string $userContent     The content to summarize (the facts block)
     * @param string $languageCode    Language the summary must be written in, e.g. 'de'
     * @param float  $temperature     Sampling temperature, sent only where the model family accepts it
     * @param int    $maxOutputTokens Output token budget
     */
    public function __construct(
        public string $systemPrompt,
        public string $userContent,
        public string $languageCode,
        public float $temperature,
        public int $maxOutputTokens,
    ) {
    }
}
