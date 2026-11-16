<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test doubles for the fact sources: Doctrine that builds real query builders (the result comes from the mocked
 * AclHelper), a query returning a fixed result, the calendar date of a user timezone and an access checker that
 * grants VIEW on the given classes only.
 */
trait FactSourceTestTrait
{
    private function createDoctrine(?EntityManagerInterface &$em = null): ManagerRegistry
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())
            ->method('createQueryBuilder')
            ->willReturnCallback(static fn () => new QueryBuilder($em));
        $em->expects(self::any())->method('getExpressionBuilder')->willReturn(new Expr());
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::any())->method('getManagerForClass')->willReturn($em);

        return $doctrine;
    }

    private function createQuery(string $method, mixed $result): Query&MockObject
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::any())->method($method)->willReturn($result);

        return $query;
    }

    private function createCalendarDateProvider(string $timezone = 'UTC'): CalendarDateProvider
    {
        $localeSettings = $this->createMock(LocaleSettings::class);
        $localeSettings->expects(self::any())->method('getTimeZone')->willReturn($timezone);

        return new CalendarDateProvider($localeSettings);
    }

    private function createAccessProvider(string ...$viewableClasses): SummaryAccessProvider
    {
        $checker = $this->createMock(SummaryAccessProvider::class);
        $checker->expects(self::any())
            ->method('canView')
            ->willReturnCallback(static fn (string $class) => \in_array($class, $viewableClasses, true));

        return $checker;
    }

    /**
     * @return Fact[]
     */
    private function collectFacts(callable $addFacts): array
    {
        return array_values(iterator_to_array($this->collectFactSet($addFacts)));
    }

    private function collectFactSet(callable $addFacts): FactSet
    {
        $facts = new FactSet();
        $addFacts($facts);

        return $facts;
    }
}
