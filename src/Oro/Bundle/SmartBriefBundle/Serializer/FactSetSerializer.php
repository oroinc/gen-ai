<?php

namespace Oro\Bundle\SmartBriefBundle\Serializer;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;

/**
 * Turns a FactSet into its storable canonical form and its fingerprint, and back.
 *
 * Values are canonicalized by their value type, so project value types serialize too. The hash covers
 * `[key, type, canonical value]` sorted by key and never the `informative` flag, labels or formatting;
 * the stored array keeps the emitted order.
 */
class FactSetSerializer
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly FactValueTypeRegistry $valueTypeRegistry
    ) {
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string|int|array, 3: bool}> [key, type, canonical, informative]
     *                                                                               in emitted order
     */
    public function toCanonicalArray(FactSet $facts): array
    {
        $data = [];
        foreach ($facts as $fact) {
            $data[] = [$fact->key, $fact->type, $this->canonicalize($fact), $fact->informative];
        }

        return $data;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string|int|array, 3: bool}> $data
     */
    public function fromCanonicalArray(array $data): FactSet
    {
        $facts = new FactSet();
        foreach ($data as $item) {
            [$key, $type, $canonical, $informative] = $item;
            $facts->add(new Fact(
                (string)$key,
                (string)$type,
                $this->valueTypeRegistry->get((string)$type)->restore($canonical),
                (bool)$informative
            ));
        }

        return $facts;
    }

    /**
     * sha256 of the key-sorted `[key, type, canonical value]` entries. Associative canonical values
     * (e.g. money) are key-sorted too, so a project value type's key order cannot change the fingerprint.
     */
    public function hash(FactSet $facts): string
    {
        $entries = [];
        foreach ($facts as $fact) {
            $entries[$fact->key] = [$fact->key, $fact->type, $this->normalize($this->canonicalize($fact))];
        }
        ksort($entries, SORT_STRING);

        return hash('sha256', json_encode(array_values($entries), self::JSON_FLAGS));
    }

    private function normalize(string|int|array $canonical): string|int|array
    {
        if (!\is_array($canonical)) {
            return $canonical;
        }

        $normalized = array_map($this->normalize(...), $canonical);
        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    private function canonicalize(Fact $fact): string|int|array
    {
        return $this->valueTypeRegistry->get($fact->type)->canonicalize($fact->value);
    }
}
