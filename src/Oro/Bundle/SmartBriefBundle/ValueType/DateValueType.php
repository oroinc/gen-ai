<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatterInterface;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A calendar date (`Y-m-d`) already in the user's timezone, or a range of them as an ISO 8601 interval
 * (`Y-m-d/Y-m-d`).
 *
 * - For the prompt the date is formatted as is; for the bullets it is followed by relative day text
 *   ("54 days ago", "today", "in 9 days") computed from today in the user's timezone.
 * - A range joins its two formatted ends with the `oro_smart_brief.range` translation, without relative
 *   text.
 */
class DateValueType implements FactValueTypeInterface
{
    private const string UTC = 'UTC';

    public function __construct(
        private readonly DateTimeFormatterInterface $dateTimeFormatter,
        private readonly TranslatorInterface $translator,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::DATE;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        // a calendar date, or a range of them as an ISO 8601 interval ("2026-09-03/2026-10-27")
        $dates = explode(FactValueType::RANGE_SEPARATOR, (string)$fact->value, 2);
        // The values are already calendar dates; formatting them in UTC avoids any timezone shift.
        $formatted = array_map(
            fn (string $date) => (string)$this->dateTimeFormatter->formatDate($date, null, null, self::UTC),
            $dates
        );
        if (2 === \count($formatted)) {
            return $this->translator->trans(
                'oro_smart_brief.range',
                ['%from%' => $formatted[0], '%to%' => $formatted[1]]
            );
        }
        if (!$forDisplay) {
            return $formatted[0];
        }

        return sprintf('%s (%s)', $formatted[0], $this->getRelativeDayText($dates[0]));
    }

    #[\Override]
    public function canonicalize(mixed $value): string
    {
        return (string)$value;
    }

    #[\Override]
    public function restore(string|int|array $canonical): string
    {
        return (string)$canonical;
    }

    private function getRelativeDayText(string $date): string
    {
        $utc = new \DateTimeZone(self::UTC);
        $days = (int)(new \DateTimeImmutable($this->calendarDateProvider->getToday(), $utc))
            ->diff(new \DateTimeImmutable($date, $utc))
            ->format('%r%a');

        if (0 === $days) {
            return $this->translator->trans('oro_smart_brief.relative.today');
        }

        return $this->translator->trans(
            $days < 0 ? 'oro_smart_brief.relative.days_ago' : 'oro_smart_brief.relative.in_days',
            ['%count%' => abs($days)]
        );
    }
}
