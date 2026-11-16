<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\SmartBriefBundle\Model\Fact;

/**
 * How a fact's raw value is formatted for the prompt and the block, and normalized for the cache.
 *
 * Implementations are tagged `oro_smart_brief.fact_value_type` and found by {@see getName()} through the registry.
 * To replace a built-in type, decorate its service (`oro_smart_brief.fact_value_type.<name>`); registering a second
 * service with the same name is a configuration error.
 */
interface FactValueTypeInterface
{
    /**
     * The value type name, e.g. 'date'.
     */
    public function getName(): string;

    /**
     * Formats the fact's value for the prompt (`forDisplay = false`) or the bullets (`forDisplay = true`) in the
     * current user's language and formatting locale, which Oro's translator and formatters use by default. The
     * whole fact is passed so a type can use the fact key (e.g. for choice translations).
     */
    public function format(Fact $fact, bool $forDisplay = false): string;

    /**
     * The stable form of a raw value, used for the cache hash and storage.
     */
    public function canonicalize(mixed $value): string|int|array;

    /**
     * The raw value rebuilt from its canonical form when read from the cache.
     *
     * The result is equal in value to the original, not necessarily in string form (a decimal may come back
     * with a different scale); formatting the restored value gives the same text as formatting the original.
     */
    public function restore(string|int|array $canonical): mixed;
}
