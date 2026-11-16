<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCache;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCacheKeyBuilder;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\SummaryTextResult;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The single entry point that turns a record into a summary.
 *
 * - Nothing is queried or called when the feature is off, no provider supports the record's class, or the
 *   provider's switch is off.
 * - With refresh-on-change enabled, the facts are computed first and any change produces a new cache key;
 *   with it disabled, a cached summary is served for the current user without any fact query. Only successful
 *   AI results are cached; concurrent misses may both call the AI.
 * - A record without informative facts gets a facts-only view, which the block shows as a "no recent activity"
 *   note; the AI is not called and nothing is cached.
 * - When the AI cannot be used ({@see SummaryTextGenerator} reports a fallback), the facts are returned as a
 *   fallback view that is never cached and a warning is logged. Any other error while resolving,
 *   computing or keying hides the block: it is logged and turned into `null`. A failed cache write
 *   after a successful AI call is only logged; the summary is still shown. Logs carry identifiers and classes
 *   only, never a message, a fact or a prompt.
 * - Every attempt ends with one structured `oro_smart_brief.generation` info entry, whatever the outcome.
 * - Nothing outside the facts reaches the AI and no business record is written.
 * - With `$refresh` (the block's Regenerate button), the cache is not read: the summary is written again and
 *   replaces the cached one; everything else (facts, fallback, logging) is the same.
 * - The caller must have verified VIEW on the record; this service does not check it. Phase 1 calls it
 *   from the widget request only, so the feature, the switches and the cache key share one scope.
 */
class SummaryGenerator
{
    public function __construct(
        private readonly SummaryProviderResolver $providerResolver,
        private readonly DoctrineHelper $doctrineHelper,
        private readonly SummaryCacheKeyBuilder $cacheKeyBuilder,
        private readonly SummaryCache $cache,
        private readonly SummaryTextGenerator $textGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function generate(object $entity, bool $refresh = false): ?SummaryView
    {
        $started = microtime(true);
        $metrics = new GenerationMetrics();
        $entityClass = $this->doctrineHelper->getEntityClass($entity);
        $logContext = ['entity_class' => $entityClass, 'entity_id' => null, 'entity_type' => null];
        try {
            $logContext['entity_id'] = $this->doctrineHelper->getSingleEntityIdentifier($entity, false);
            $provider = $this->providerResolver->resolve($entity);
            if (null === $provider) {
                $this->markNotEligible($metrics, $entityClass);

                return null;
            }
            $logContext['entity_type'] = $provider->getEntityType();
            $metrics->setEntityType($provider->getEntityType());

            return $this->doGenerate($provider, $entity, $entityClass, $logContext, $metrics, $refresh);
        } catch (\Throwable $exception) {
            $metrics->markHidden(GenerationMetrics::HIDDEN_ERROR);
            $this->logger->error(
                'SmartBrief hidden after an error.',
                $logContext + ['exception_class' => $exception::class]
            );

            return null;
        } finally {
            $this->logMetrics($metrics, $started);
        }
    }

    private function markNotEligible(GenerationMetrics $metrics, string $entityClass): void
    {
        $knownProvider = $this->providerResolver->findProvider($entityClass);
        if (null !== $knownProvider) {
            $metrics->setEntityType($knownProvider->getEntityType());
        }
        $metrics->markHidden(
            $this->providerResolver->getIneligibilityReason($entityClass) ?? GenerationMetrics::HIDDEN_SWITCH_OFF
        );
    }

    /**
     * Best effort: a failing log handler must not turn a generated summary into an error.
     */
    private function logMetrics(GenerationMetrics $metrics, float $started): void
    {
        try {
            $this->logger->info(GenerationMetrics::LOG_MESSAGE, $metrics->toContext($this->getLatencyMs($started)));
        } catch (\Throwable) {
            // nothing to do: the entry is lost, the summary is not
        }
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function doGenerate(
        SummaryFactProviderInterface $provider,
        object $entity,
        string $entityClass,
        array $logContext,
        GenerationMetrics $metrics,
        bool $refresh
    ): ?SummaryView {
        $entityId = $this->getEntityId($logContext['entity_id'], $entityClass);

        // The key builder re-reads the same flag; facts are passed only in that mode.
        $refreshOnChange = $this->cacheKeyBuilder->isRefreshOnChangeEnabled();
        $metrics->setRefreshOnChange($refreshOnChange);
        if ($refreshOnChange) {
            $facts = $provider->getFacts($entity);
            if (!$facts->hasInformativeFacts()) {
                return $this->createNoActivityView($facts, $metrics);
            }
            $key = $this->cacheKeyBuilder->build(
                $provider->getEntityType(),
                $entityClass,
                $entityId,
                $facts
            );
            $cached = $refresh ? null : $this->cache->get($key);
            if (null !== $cached) {
                $metrics->markCacheHit();

                // the key proves the facts are the same; the ones just computed also carry the current records
                return new SummaryView($cached->text, $facts, false, $cached->generatedAt);
            }
            $metrics->markCacheMiss();

            return $this->generateFromFacts($provider, $facts, $key, $logContext, $metrics);
        }

        $key = $this->cacheKeyBuilder->build($provider->getEntityType(), $entityClass, $entityId);
        $cached = $refresh ? null : $this->cache->get($key);
        if (null !== $cached) {
            $metrics->markCacheHit();

            return $cached;
        }
        $metrics->markCacheMiss();

        $facts = $provider->getFacts($entity);
        if (!$facts->hasInformativeFacts()) {
            return $this->createNoActivityView($facts, $metrics);
        }

        return $this->generateFromFacts($provider, $facts, $key, $logContext, $metrics);
    }

    private function createNoActivityView(FactSet $facts, GenerationMetrics $metrics): SummaryView
    {
        $metrics->markNoActivity();

        return new SummaryView(null, $facts, true, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function generateFromFacts(
        SummaryFactProviderInterface $provider,
        FactSet $facts,
        string $key,
        array $logContext,
        GenerationMetrics $metrics
    ): SummaryView {
        $result = $this->textGenerator->generate($provider->getEntityType(), $facts);
        $generatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $metrics->markGenerated($result);
        if ($result->isFallback()) {
            $this->logger->warning('SmartBrief fell back to the facts.', $logContext + $this->toContext($result));

            return new SummaryView(null, $facts, true, $generatedAt);
        }

        $view = new SummaryView($result->text, $facts, false, $generatedAt);
        try {
            $this->cache->save($key, $view);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'SmartBrief could not be cached.',
                $logContext + ['exception_class' => $exception::class]
            );
        }

        return $view;
    }

    /**
     * @return array<string, mixed> the part of the warning context that may be logged (classes and ids, no text)
     */
    private function toContext(SummaryTextResult $result): array
    {
        return [
            'channel_type' => $result->channelType,
            'reason' => $result->reason,
            'http_status' => $result->httpStatus,
            'provider_error_code' => $result->providerErrorCode,
        ];
    }

    /**
     * A record without a persisted single identifier cannot be keyed; hiding the block beats one
     * shared key.
     */
    private function getEntityId(mixed $entityId, string $entityClass): int|string
    {
        if (!\is_int($entityId) && !(\is_string($entityId) && '' !== $entityId)) {
            throw new \LogicException(sprintf('Entity "%s" has no usable single identifier.', $entityClass));
        }

        return $entityId;
    }

    /**
     * Wall-clock difference; clamped because a clock step during the AI call could make it negative.
     */
    private function getLatencyMs(float $started): int
    {
        return max(0, (int)round((microtime(true) - $started) * 1000));
    }
}
