<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Serializer;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatterInterface;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\ProjectArrayValueTypeStub;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\ProjectSkuValueTypeStub;
use Oro\Bundle\SmartBriefBundle\ValueType\DateValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;
use Oro\Bundle\SmartBriefBundle\ValueType\IntegerValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\MoneyValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\PercentValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\TextValueType;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Canonical array in emitted order, restore round trip, hash stability.
 */
final class FactSetSerializerTest extends TestCase
{
    private FactSetSerializer $serializer;

    #[\Override]
    protected function setUp(): void
    {
        $numberFormatter = $this->createMock(NumberFormatter::class);
        $this->serializer = new FactSetSerializer(new FactValueTypeRegistry([
            new DateValueType(
                $this->createMock(DateTimeFormatterInterface::class),
                $this->createMock(TranslatorInterface::class),
                $this->createMock(CalendarDateProvider::class)
            ),
            new IntegerValueType($numberFormatter),
            new PercentValueType($numberFormatter, $this->createMock(TranslatorInterface::class)),
            new MoneyValueType($numberFormatter),
            new TextValueType(200),
            new ProjectSkuValueTypeStub(),
        ]));
    }

    public function testToCanonicalArrayKeepsEmittedOrderAndInformativeFlag(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('last_order_total', FactValueType::MONEY, Price::create('12480', 'USD')));
        $facts->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));
        $facts->add(new Fact('project_sku', 'project_sku', 'abc-1'));

        self::assertSame(
            [
                ['last_order_total', 'money', ['amount' => '12480.0000', 'currency' => 'USD'], true],
                ['company_name', 'text', 'Acme', false],
                ['project_sku', 'project_sku', 'ABC-1', true],
            ],
            $this->serializer->toCanonicalArray($facts)
        );
    }

    public function testRoundTrip(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('b_gap', FactValueType::PERCENT, '-2.25/8.3'));
        $facts->add(new Fact('a_date', FactValueType::DATE, '2026-08-14', false));
        $facts->add(new Fact('total', FactValueType::MONEY, Price::create('12480', 'USD')));
        $facts->add(new Fact('sku', 'project_sku', 'abc-1'));

        $restored = $this->serializer->fromCanonicalArray($this->serializer->toCanonicalArray($facts));

        self::assertEquals(
            [
                new Fact('b_gap', FactValueType::PERCENT, '-2.3/8.3'),
                new Fact('a_date', FactValueType::DATE, '2026-08-14', false),
                new Fact('total', FactValueType::MONEY, Price::create('12480.0000', 'USD')),
                new Fact('sku', 'project_sku', 'ABC-1'),
            ],
            iterator_to_array($restored)
        );
        self::assertSame($this->serializer->hash($facts), $this->serializer->hash($restored));
    }

    public function testHashIsStableAcrossOrderScaleAndInformativeFlag(): void
    {
        $base = $this->serializer->hash($this->createFacts('12480', 1200, true));

        self::assertSame($base, $this->serializer->hash($this->createFacts('12480.0000', 1200, true)));
        self::assertSame($base, $this->serializer->hash($this->createFacts('12480', 1200, false)));
        self::assertSame($base, $this->serializer->hash($this->createFacts('12480', 1200, true, true)));
        self::assertNotSame($base, $this->serializer->hash($this->createFacts('12480', 1201, true)));
        self::assertNotSame($base, $this->serializer->hash($this->createFacts('12480.5', 1200, true)));
        self::assertSame(64, \strlen($base));
    }

    public function testHashIgnoresKeyOrderOfAssociativeCanonicalValues(): void
    {
        $forward = new FactSet();
        $forward->add(new Fact('money', 'project_money', ['amount' => '1.0000', 'currency' => 'USD']));
        $backward = new FactSet();
        $backward->add(new Fact('money', 'project_money', ['currency' => 'USD', 'amount' => '1.0000']));
        $serializer = new FactSetSerializer(new FactValueTypeRegistry([new ProjectArrayValueTypeStub()]));

        self::assertSame($serializer->hash($forward), $serializer->hash($backward));
    }

    public function testHashOfEmptySet(): void
    {
        self::assertSame(hash('sha256', '[]'), $this->serializer->hash(new FactSet()));
    }

    public function testUnknownTypeIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->serializer->fromCanonicalArray([['k', 'unknown', 'v', true]]);
    }

    private function createFacts(string $amount, int $count, bool $informative, bool $reverse = false): FactSet
    {
        $list = [
            new Fact('last_order_total', FactValueType::MONEY, Price::create($amount, 'USD'), $informative),
            new Fact('open_rfq_count', FactValueType::INTEGER, $count),
            new Fact('last_order_date', FactValueType::DATE, '2026-08-14'),
        ];
        $facts = new FactSet();
        foreach ($reverse ? array_reverse($list) : $list as $fact) {
            $facts->add($fact);
        }

        return $facts;
    }
}
