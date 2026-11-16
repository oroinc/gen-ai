<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Rfq;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Query\LiveQuoteCriteria;
use Oro\Bundle\SmartBriefBundle\Provider\Rfq\RfqQuoteSource;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Covers the RFQ quote facts: the active quote count always, the linked
 * quote's QID as text and its status as the full enum option id, a QID without a visible character skipped, nothing
 * read without VIEW on Quote. The queries themselves are covered by the functional test.
 */
final class RfqQuoteSourceTest extends TestCase
{
    use FactSourceTestTrait;

    /**
     * @dataProvider quoteDataProvider
     */
    public function testFacts(?array $quote, array $expected, ?array $expectedQuoteRecords = null): void
    {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::exactly(3))
            ->method('apply')
            ->willReturnOnConsecutiveCalls(
                $this->createQuery('getSingleScalarResult', '2'),
                $this->createQuery('getSingleColumnResult', ['Q-1', 9]),
                $this->createQuery('getOneOrNullResult', $quote)
            );
        $source = new RfqQuoteSource(
            $this->createDoctrine(),
            $aclHelper,
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider(Quote::class)
        );

        $facts = $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Request(), new Customer()));

        self::assertEquals($expected, array_values(iterator_to_array($facts)));
        self::assertSame(['Q-1', '9'], $facts->getRecords('active_quote_count'), 'The QIDs of the active quotes.');
        self::assertSame(
            $expectedQuoteRecords,
            $facts->getRecords('quote_for_this_rfq'),
            'The QID of this RFQ\'s quote.'
        );
    }

    public function testMoreActiveQuotesThanTheLinkLimitReadNoQids(): void
    {
        $aclHelper = $this->createMock(AclHelper::class);
        $aclHelper->expects(self::exactly(2))
            ->method('apply')
            ->willReturnOnConsecutiveCalls(
                $this->createQuery('getSingleScalarResult', '21'),
                $this->createQuery('getOneOrNullResult', null)
            );
        $source = new RfqQuoteSource(
            $this->createDoctrine(),
            $aclHelper,
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider(Quote::class)
        );

        $facts = $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Request(), new Customer()));

        self::assertNull($facts->getRecords('active_quote_count'));
    }

    public static function quoteDataProvider(): array
    {
        $count = new Fact('active_quote_count', FactValueType::INTEGER, 2);

        return [
            'no linked quote' => [null, [$count]],
            'linked quote' => [
                ['qid' => '42', 'internalStatus' => 'quote_internal_status.sent_to_customer'],
                [
                    $count,
                    new Fact('quote_for_this_rfq', FactValueType::TEXT, '42'),
                    new Fact(
                        'quote_for_this_rfq_status',
                        FactValueType::ENUM_OPTION,
                        'quote_internal_status.sent_to_customer'
                    ),
                ],
                ['42'],
            ],
            'linked quote without status' => [
                ['qid' => '42', 'internalStatus' => null],
                [$count, new Fact('quote_for_this_rfq', FactValueType::TEXT, '42')],
                ['42'],
            ],
            'a blank QID is skipped' => [
                ['qid' => "  ", 'internalStatus' => 'quote_internal_status.draft'],
                [$count],
            ],
        ];
    }

    public function testWithoutViewOnQuoteNothingIsRead(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('getManagerForClass');
        $source = new RfqQuoteSource(
            $doctrine,
            $this->createMock(AclHelper::class),
            $this->createMock(LiveQuoteCriteria::class),
            $this->createAccessProvider()
        );

        self::assertSame(
            [],
            $this->collectFacts(static fn ($facts) => $source->addFacts($facts, new Request(), new Customer()))
        );
    }
}
