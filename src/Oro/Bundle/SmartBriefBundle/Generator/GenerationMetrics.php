<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Oro\Bundle\SmartBriefBundle\Model\SummaryTextResult;

/**
 * Collects what one generation attempt did and turns it into the structured `oro_smart_brief.generation` log
 * context: entity and channel type, model, cache hit or miss, refresh mode, latency, tokens when the
 * provider reports them and the outcome with its reason. Values are enums, ids and numbers only.
 * Created per attempt by {@see SummaryGenerator}; not a service.
 */
final class GenerationMetrics
{
    public const string LOG_MESSAGE = 'oro_smart_brief.generation';

    public const string OUTCOME_OK = 'ok';
    public const string OUTCOME_FALLBACK = 'fallback';
    public const string OUTCOME_HIDDEN = 'hidden';
    /** The block shows a "no recent activity" note; the AI is not called. */
    public const string OUTCOME_NO_ACTIVITY = 'no_activity';

    public const string HIDDEN_FEATURE_OFF = 'feature_off';
    public const string HIDDEN_SWITCH_OFF = 'switch_off';
    public const string HIDDEN_NO_PROVIDER = 'no_provider';
    public const string HIDDEN_ERROR = 'error';
    public const array HIDDEN_REASONS = [
        self::HIDDEN_FEATURE_OFF,
        self::HIDDEN_SWITCH_OFF,
        self::HIDDEN_NO_PROVIDER,
        self::HIDDEN_ERROR,
    ];

    public const string CACHE_HIT = 'hit';
    public const string CACHE_MISS = 'miss';
    public const string CACHE_NONE = 'none';

    private ?string $entityType = null;
    private ?string $channelType = null;
    private ?string $model = null;
    private string $cache = self::CACHE_NONE;
    private ?bool $refreshOnChange = null;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;
    private string $outcome = self::OUTCOME_HIDDEN;
    private ?string $fallbackReason = null;
    private ?int $httpStatus = null;
    private ?string $hiddenReason = self::HIDDEN_ERROR;

    public function setEntityType(string $entityType): void
    {
        $this->entityType = $entityType;
    }

    public function setRefreshOnChange(bool $refreshOnChange): void
    {
        $this->refreshOnChange = $refreshOnChange;
    }

    public function markCacheHit(): void
    {
        $this->cache = self::CACHE_HIT;
        $this->outcome = self::OUTCOME_OK;
        $this->hiddenReason = null;
    }

    public function markCacheMiss(): void
    {
        $this->cache = self::CACHE_MISS;
    }

    public function markGenerated(SummaryTextResult $result): void
    {
        $this->channelType = $result->channelType;
        $this->model = $result->model;
        $this->inputTokens = $result->inputTokens;
        $this->outputTokens = $result->outputTokens;
        $this->hiddenReason = null;
        if ($result->isFallback()) {
            $this->outcome = self::OUTCOME_FALLBACK;
            $this->fallbackReason = $result->reason;
            $this->httpStatus = $result->httpStatus;
        } else {
            $this->outcome = self::OUTCOME_OK;
        }
    }

    public function markNoActivity(): void
    {
        $this->outcome = self::OUTCOME_NO_ACTIVITY;
        $this->hiddenReason = null;
    }

    /**
     * @param string $reason one of the HIDDEN_* constants
     */
    public function markHidden(string $reason): void
    {
        if (!\in_array($reason, self::HIDDEN_REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown hidden reason "%s".', $reason));
        }
        $this->outcome = self::OUTCOME_HIDDEN;
        $this->hiddenReason = $reason;
    }

    /**
     * @return array<string, scalar|null> the log context; token counts appear only when the provider reported them
     */
    public function toContext(int $latencyMs): array
    {
        $context = [
            'entity_type' => $this->entityType,
            'channel_type' => $this->channelType,
            'model' => $this->model,
            'cache' => $this->cache,
            'refresh_on_change' => $this->refreshOnChange,
            'latency_ms' => $latencyMs,
            'outcome' => $this->outcome,
        ];
        if (null !== $this->inputTokens) {
            $context['input_tokens'] = $this->inputTokens;
        }
        if (null !== $this->outputTokens) {
            $context['output_tokens'] = $this->outputTokens;
        }
        if (self::OUTCOME_FALLBACK === $this->outcome) {
            $context['fallback_reason'] = $this->fallbackReason;
            $context['http_status'] = $this->httpStatus;
        }
        if (self::OUTCOME_HIDDEN === $this->outcome) {
            $context['hidden_reason'] = $this->hiddenReason;
        }

        return $context;
    }
}
