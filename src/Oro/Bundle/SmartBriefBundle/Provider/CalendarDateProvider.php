<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Oro\Bundle\LocaleBundle\Model\LocaleSettings;

/**
 * The single source of "today" and of date facts: calendar dates in the current user's timezone.
 *
 * Stored datetimes are UTC; converting them here keeps the bullet, the prompt and the cache hash on the same
 * calendar day. There is deliberately no day-count method: relative day text is computed only when the
 * bullets are rendered.
 */
class CalendarDateProvider
{
    private const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly LocaleSettings $localeSettings
    ) {
    }

    /**
     * Today's calendar date (`Y-m-d`) in the user's timezone.
     */
    public function getToday(): string
    {
        return $this->toDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * The calendar date (`Y-m-d`) of a stored datetime in the user's timezone.
     */
    public function toDate(\DateTimeInterface $dateTime): string
    {
        return \DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new \DateTimeZone($this->localeSettings->getTimeZone()))
            ->format(self::DATE_FORMAT);
    }
}
