<?php

namespace Oro\Bundle\SmartBriefBundle\Model;

/**
 * One deterministic value computed by Oro about the subject entity: a key, a value type and the raw value.
 */
final readonly class Fact
{
    /**
     * @param string $key         e.g. 'last_order_date', 'item_3_list_gap_percent'
     * @param string $type        a registered value type name, e.g. {@see FactValueType::DATE}
     * @param mixed  $value       raw value: `Y-m-d` string (date), int (integer), decimal string (decimal, percent),
     *                            Oro's {@see \Oro\Bundle\CurrencyBundle\Entity\Price} (money), string (text,
     *                            enum_option, unit, choice)
     * @param bool   $informative false for identity facts such as the company name, which alone do not justify
     *                            an AI summary
     */
    public function __construct(
        public string $key,
        public string $type,
        public mixed $value,
        public bool $informative = true,
    ) {
    }

    /**
     * A count fact; a zero count is not informative: a 0 alone does not justify an AI summary, so a record
     * with nothing but zero counts shows the "no recent activity" note without an AI call.
     */
    public static function count(string $key, int $count): self
    {
        return new self($key, FactValueType::INTEGER, $count, $count > 0);
    }
}
