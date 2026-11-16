<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Customer;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\OpenRfqSource;
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers the open RFQ facts: the count always (not informative at zero), the
 * oldest date in the user's calendar only when there is one, nothing read without VIEW on both Request and Quote.
 * The query itself is covered by the functional test.
 */
final class OpenRfqSourceTest extends TestCase
{
    use FactSourceTestTrait;

    /**
     * @dataProvider rowDataProvider
     */
    public function testFacts(
        array $row,
        string $timezone,
        array $expected,
        ?array $ids = null,
        ?array $expectedRecords = null
    ): void {
        $queries = [$this->createQuery('getSingleResult', $row)];
        if (null !== $ids) {
            $queries[] = $this->createQuery('getSingleColumnResult', $ids);
        }
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::exactly(\count($queries)))
            ->method('apply')
            ->willReturnOnConsecutiveCalls(...$queries);
        $source = $this->createSource($aclHelper, $this->createDoctrine(), [Request::class, Quote::class], $timezone);

        $facts = $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Customer()));

        self::assertEquals($expected, array_values(iterator_to_array($facts)));
        self::assertSame($expectedRecords, $facts->getRecords('open_rfq_count'), 'The RFQ ids to link to.');
    }

    public static function rowDataProvider(): array
    {
        return [
            'none open: the count alone, not informative' => [
                ['rfqCount' => 0, 'oldest' => null],
                'UTC',
                [new Fact('open_rfq_count', FactValueType::INTEGER, 0, false)],
            ],
            'two open: count and the oldest date' => [
                ['rfqCount' => 2, 'oldest' => '2026-07-01 10:00:00'],
                'UTC',
                [
                    new Fact('open_rfq_count', FactValueType::INTEGER, 2),
                    new Fact('oldest_open_rfq_date', FactValueType::DATE, '2026-07-01'),
                ],
                ['3', '7'],
                [3, 7],
            ],
            'Los Angeles, early UTC is the day before' => [
                ['rfqCount' => 1, 'oldest' => '2026-07-01 03:00:00'],
                'America/Los_Angeles',
                [
                    new Fact('open_rfq_count', FactValueType::INTEGER, 1),
                    new Fact('oldest_open_rfq_date', FactValueType::DATE, '2026-06-30'),
                ],
                [5],
                [5],
            ],
            'more than the link limit: no ids are read' => [
                ['rfqCount' => 21, 'oldest' => '2026-07-01 10:00:00'],
                'UTC',
                [
                    new Fact('open_rfq_count', FactValueType::INTEGER, 21),
                    new Fact('oldest_open_rfq_date', FactValueType::DATE, '2026-07-01'),
                ],
            ],
        ];
    }

    /**
     * @dataProvider partialAccessDataProvider
     */
    public function testNeedsViewOnBothRequestAndQuote(array $viewable): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManagerForClass');
        $source = $this->createSource($this->createMock(AclHelper::class), $doctrine, $viewable);

        self::assertSame([], $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Customer())));
    }

    public static function partialAccessDataProvider(): array
    {
        return ['only RFQs' => [[Request::class]], 'only quotes' => [[Quote::class]], 'neither' => [[]]];
    }

    private function createSource(
        AclHelper $aclHelper,
        ManagerRegistry $doctrine,
        array $viewable,
        string $timezone = 'UTC'
    ): OpenRfqSource {
        return new OpenRfqSource(
            $doctrine,
            $aclHelper,
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider(...$viewable),
            $this->createCalendarDateProvider($timezone)
        );
    }
}
