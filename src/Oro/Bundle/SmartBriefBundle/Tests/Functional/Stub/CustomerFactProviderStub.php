<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;

/**
 * Decorates the real Customer fact provider in the test container. Switched off (the default) it delegates every
 * call; switched on by a page or widget test it emits a count and a date 54 days back — a presentation fixture whose
 * output does not depend on today's date or on any data source. Only Customer facts the real provider can emit
 * are simulated; item groups are rendered from real RFQ facts by the RFQ widget test.
 */
class CustomerFactProviderStub implements SummaryFactProviderInterface
{
    private static bool $enabled = false;

    public const string FACT_KEY = 'open_rfq_count';
    public const string DATE_FACT_KEY = 'last_order_date';
    public const int DATE_FACT_DAYS_AGO = 54;

    public function __construct(
        private readonly SummaryFactProviderInterface $inner,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function reset(): void
    {
        self::$enabled = false;
    }

    #[\Override]
    public function supportsClass(string $entityClass): bool
    {
        return $this->inner->supportsClass($entityClass);
    }

    #[\Override]
    public function getEntityType(): string
    {
        return $this->inner->getEntityType();
    }

    #[\Override]
    public function getEnabledConfigKey(): string
    {
        return $this->inner->getEnabledConfigKey();
    }

    #[\Override]
    public function getFacts(object $entity): FactSet
    {
        if (!self::$enabled) {
            return $this->inner->getFacts($entity);
        }

        $facts = new FactSet();
        $facts->add(new Fact(self::FACT_KEY, FactValueType::INTEGER, 1));
        $date = $this->getDateDaysAgo(self::DATE_FACT_DAYS_AGO);
        $facts->add(new Fact(self::DATE_FACT_KEY, FactValueType::DATE, $date));

        return $facts;
    }

    public function getDateDaysAgo(int $days): string
    {
        return (new \DateTimeImmutable($this->calendarDateProvider->getToday(), new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days))
            ->format('Y-m-d');
    }
}
