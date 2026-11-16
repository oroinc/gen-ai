<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;

/**
 * A whole number, formatted with the locale's grouping separators.
 */
class IntegerValueType implements FactValueTypeInterface
{
    public function __construct(
        private readonly NumberFormatter $numberFormatter
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::INTEGER;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return (string)$this->numberFormatter->formatDecimal((int)$fact->value);
    }

    #[\Override]
    public function canonicalize(mixed $value): int
    {
        return (int)$value;
    }

    #[\Override]
    public function restore(string|int|array $canonical): int
    {
        return (int)$canonical;
    }
}
