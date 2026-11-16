<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Model;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use PHPUnit\Framework\TestCase;

/**
 * Emission order, duplicate keys rejected, isEmpty, hasInformativeFacts, count.
 */
final class FactSetTest extends TestCase
{
    public function testEmptySet(): void
    {
        $facts = new FactSet();

        self::assertTrue($facts->isEmpty());
        self::assertFalse($facts->hasInformativeFacts());
        self::assertCount(0, $facts);
        self::assertSame([], iterator_to_array($facts));
    }

    public function testKeepsEmissionOrder(): void
    {
        $facts = new FactSet();
        $b = new Fact('b', FactValueType::INTEGER, 2);
        $a = new Fact('a', FactValueType::INTEGER, 1);

        $facts->add($b);
        $facts->add($a);

        self::assertCount(2, $facts);
        self::assertSame([$b, $a], iterator_to_array($facts));
        self::assertFalse($facts->isEmpty());
    }

    public function testDuplicateKeyIsRejected(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('b', FactValueType::INTEGER, 2));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The fact "b" was already added to this set.');

        $facts->add(new Fact('b', FactValueType::INTEGER, 3));
    }

    public function testHasInformativeFacts(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));

        self::assertFalse($facts->hasInformativeFacts());

        $facts->add(new Fact('open_rfq_count', FactValueType::INTEGER, 2));

        self::assertTrue($facts->hasInformativeFacts());
    }

    public function testGetReturnsTheFactByKey(): void
    {
        $facts = new FactSet();
        $fact = new Fact('a', FactValueType::INTEGER, 1);
        $facts->add($fact);

        self::assertSame($fact, $facts->get('a'));
        self::assertNull($facts->get('missing'));
    }

    public function testRecordsAreKeptPerFactWithoutBeingFacts(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('open_rfq_count', FactValueType::INTEGER, 2));
        $facts->setRecords('open_rfq_count', [4, 10]);

        self::assertSame([4, 10], $facts->getRecords('open_rfq_count'));
        self::assertNull($facts->getRecords('other'));
        self::assertSame(['open_rfq_count' => [4, 10]], $facts->getAllRecords());
        self::assertCount(1, $facts, 'Records are not facts.');
    }

    public function testMoreRecordsThanTheLimitAreRejected(): void
    {
        $this->expectException(\LogicException::class);

        (new FactSet())->setRecords('open_rfq_count', range(1, FactSet::RECORD_LIMIT + 1));
    }
}
