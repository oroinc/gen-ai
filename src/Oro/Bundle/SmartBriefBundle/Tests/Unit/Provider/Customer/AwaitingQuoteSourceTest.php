<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Customer;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\AwaitingQuoteSource;
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers the facts of the quotes awaiting the customer's reply: the count always,
 * the nearest expiry in the user's calendar only when one quote has a valid-until value, nothing read without VIEW
 * on Quote. The query itself is covered by the functional test.
 */
final class AwaitingQuoteSourceTest extends TestCase
{
    use FactSourceTestTrait;

    /**
     * @dataProvider rowDataProvider
     */
    public function testFacts(
        string $timezone,
        array $row,
        array $expected,
        ?array $qids = null,
        ?array $expectedRecords = null
    ): void {
        $queries = [$this->createQuery('getSingleResult', $row)];
        if (null !== $qids) {
            $queries[] = $this->createQuery('getSingleColumnResult', $qids);
        }
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::exactly(\count($queries)))
            ->method('apply')
            ->willReturnOnConsecutiveCalls(...$queries);
        $source = new AwaitingQuoteSource(
            $this->createDoctrine(),
            $aclHelper,
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider(Quote::class),
            $this->createCalendarDateProvider($timezone)
        );

        $facts = $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Customer()));

        self::assertEquals($expected, array_values(iterator_to_array($facts)), 'Dates only, no days-until fact.');
        self::assertSame($expectedRecords, $facts->getRecords('awaiting_quote_count'), 'The QIDs to link to.');
    }

    public static function rowDataProvider(): array
    {
        return [
            'none: the count alone, not informative' => [
                'UTC',
                ['quoteCount' => 0, 'nearest' => null],
                [new Fact('awaiting_quote_count', FactValueType::INTEGER, 0, false)],
            ],
            'without validUntil: the count alone' => [
                'UTC',
                ['quoteCount' => 2, 'nearest' => null],
                [new Fact('awaiting_quote_count', FactValueType::INTEGER, 2)],
                ['Q-1', 42],
                ['Q-1', '42'],
            ],
            'Berlin, morning UTC' => [
                'Europe/Berlin',
                ['quoteCount' => 1, 'nearest' => '2026-10-16 10:00:00'],
                [
                    new Fact('awaiting_quote_count', FactValueType::INTEGER, 1),
                    new Fact('nearest_quote_expiry_date', FactValueType::DATE, '2026-10-16'),
                ],
                ['7'],
                ['7'],
            ],
            'Berlin, later today in UTC is tomorrow for the user' => [
                'Europe/Berlin',
                ['quoteCount' => 1, 'nearest' => '2026-10-07 23:30:00'],
                [
                    new Fact('awaiting_quote_count', FactValueType::INTEGER, 1),
                    new Fact('nearest_quote_expiry_date', FactValueType::DATE, '2026-10-08'),
                ],
                ['8'],
                ['8'],
            ],
            'more than the link limit: no QIDs are read' => [
                'UTC',
                ['quoteCount' => 25, 'nearest' => null],
                [new Fact('awaiting_quote_count', FactValueType::INTEGER, 25)],
            ],
        ];
    }

    public function testWithoutViewOnQuoteNothingIsRead(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManagerForClass');
        $source = new AwaitingQuoteSource(
            $doctrine,
            $this->createMock(AclHelper::class),
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider(),
            $this->createCalendarDateProvider()
        );

        self::assertSame([], $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Customer())));
    }
}
