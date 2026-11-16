<?php

namespace Oro\Bundle\SmartBriefBundle\Cache;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Stores successfully generated summaries (never a fallback view: it has no text) in the `oro_smart_brief.cache`
 * pool for `oro_smart_brief.cache_ttl` seconds. Nothing invalidates entries except
 * their lifetime, a key-format version bump or a manual pool clear.
 */
class SummaryCache
{
    private const string TEXT = 'text';
    private const string FACTS = 'facts';
    private const string RECORDS = 'records';
    private const string GENERATED_AT = 'generatedAt';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ConfigManager $configManager,
        private readonly FactSetSerializer $serializer
    ) {
    }

    public function get(string $key): ?SummaryView
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        if (!$this->isValidValue($value)) {
            $this->cache->deleteItem($key);

            return null;
        }

        try {
            $facts = $this->serializer->fromCanonicalArray($value[self::FACTS]);
            foreach ($value[self::RECORDS] ?? [] as $key => $identifiers) {
                $facts->setRecords((string)$key, $identifiers);
            }

            $generatedAt = new \DateTimeImmutable($value[self::GENERATED_AT]);

            return new SummaryView($value[self::TEXT], $facts, false, $generatedAt);
        } catch (\Throwable) {
            // an entry written by another version of the bundle or a removed value type is a miss, not an error
            $this->cache->deleteItem($key);

            return null;
        }
    }

    public function save(string $key, SummaryView $summary): void
    {
        $item = $this->cache->getItem($key);
        $item->set([
            self::TEXT => $summary->text,
            self::FACTS => $this->serializer->toCanonicalArray($summary->facts),
            self::RECORDS => $summary->facts->getAllRecords(),
            self::GENERATED_AT => $summary->generatedAt->format(\DateTimeInterface::ATOM),
        ]);
        $item->expiresAfter($this->getTtl());
        $this->cache->save($item);
    }

    private function getTtl(): int
    {
        return max(1, (int)$this->configManager->get(Configuration::getConfigKeyByName(Configuration::CACHE_TTL_KEY)));
    }

    private function isValidValue(mixed $value): bool
    {
        return \is_array($value)
            && \is_string($value[self::TEXT] ?? null)
            && \is_array($value[self::FACTS] ?? null)
            && \is_string($value[self::GENERATED_AT] ?? null);
    }
}
