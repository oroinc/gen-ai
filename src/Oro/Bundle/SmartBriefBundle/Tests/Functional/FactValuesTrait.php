<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional;

use Oro\Bundle\SmartBriefBundle\Model\FactSet;

/**
 * Reads what a fact source emits: the values of its facts by key, and a stored time as the calendar date the
 * sources emit (the user's timezone).
 */
trait FactValuesTrait
{
    /**
     * @param callable(FactSet): void $addFacts
     *
     * @return array<string, mixed>
     */
    private function getFactValues(callable $addFacts): array
    {
        $facts = new FactSet();
        $addFacts($facts);

        $values = [];
        foreach ($facts as $fact) {
            $values[$fact->key] = $fact->value;
        }

        return $values;
    }

    private function toUserDate(\DateTimeInterface $dateTime): string
    {
        return self::getContainer()->get('oro_smart_brief.tests.provider.calendar_date')->toDate($dateTime);
    }
}
