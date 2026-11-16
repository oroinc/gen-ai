<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Cache;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCache;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;
use Oro\Bundle\SmartBriefBundle\ValueType\IntegerValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\TextValueType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Covers the TTL, the stored shape, the round trip and the miss / malformed-entry paths.
 */
final class SummaryCacheTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private FactSetSerializer $serializer;

    #[\Override]
    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->serializer = new FactSetSerializer(new FactValueTypeRegistry([
            new IntegerValueType($this->createMock(NumberFormatter::class)),
            new TextValueType(200),
        ]));
    }

    public function testSaveAndGetRoundTrip(): void
    {
        // the item expiry is an absolute timestamp, so the mock clock starts at the real current time
        $clock = new MockClock();
        $pool = new ArrayAdapter(0, true, 0, 0, $clock);
        $cache = new SummaryCache($pool, $this->configManager, $this->serializer);
        $this->expectTtl(60);
        $facts = new FactSet();
        $facts->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));
        $facts->add(new Fact('open_rfq_count', FactValueType::INTEGER, 2));
        $facts->setRecords('open_rfq_count', [4, 10]);
        $generatedAt = new \DateTimeImmutable('2026-10-07T12:00:00+00:00');

        self::assertNull($cache->get('key'));

        $cache->save('key', new SummaryView('Summary.', $facts, false, $generatedAt));
        $cached = $cache->get('key');

        self::assertNotNull($cached);
        self::assertSame('Summary.', $cached->text);
        self::assertEquals(iterator_to_array($facts), iterator_to_array($cached->facts));
        self::assertSame(['open_rfq_count' => [4, 10]], $cached->facts->getAllRecords(), 'The records are kept.');
        self::assertEquals($generatedAt, $cached->generatedAt);

        $clock->sleep(59);
        self::assertNotNull($cache->get('key'));
        // the stored expiry is real time + 60 s; a generous jump keeps the proof independent of test duration
        $clock->sleep(120);
        self::assertNull($cache->get('key'), 'the item expires after cache_ttl seconds');
    }

    /**
     * @dataProvider ttlDataProvider
     */
    public function testSaveSetsTtlFromConfiguration(int $configuredTtl, int $expectedTtl): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with([
            'text' => 'Summary.',
            'facts' => [['open_rfq_count', 'integer', 2, true]],
            'records' => ['open_rfq_count' => [4, 10]],
            'generatedAt' => '2026-10-07T12:00:00+00:00',
        ])->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with($expectedTtl)->willReturnSelf();
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::once())->method('getItem')->with('key')->willReturn($item);
        $pool->expects(self::once())->method('save')->with($item)->willReturn(true);
        $this->expectTtl($configuredTtl);
        $facts = new FactSet();
        $facts->add(new Fact('open_rfq_count', FactValueType::INTEGER, 2));
        $facts->setRecords('open_rfq_count', [4, 10]);

        (new SummaryCache($pool, $this->configManager, $this->serializer))
            ->save(
                'key',
                new SummaryView('Summary.', $facts, false, new \DateTimeImmutable('2026-10-07T12:00:00+00:00'))
            );
    }

    public static function ttlDataProvider(): array
    {
        return [
            'default' => [14400, 14400],
            'zero never means forever' => [0, 1],
            'negative never means forever' => [-5, 1],
        ];
    }

    /**
     * @dataProvider malformedValueDataProvider
     */
    public function testMalformedEntryIsAMissAndIsDropped(mixed $value): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem('key');
        $item->set($value);
        $pool->save($item);

        self::assertNull((new SummaryCache($pool, $this->configManager, $this->serializer))->get('key'));
        self::assertFalse($pool->hasItem('key'));
    }

    public static function malformedValueDataProvider(): array
    {
        return [
            'string' => ['text'],
            'missing facts' => [['text' => 'x', 'generatedAt' => '2026-10-07T12:00:00+00:00']],
            'missing text' => [['facts' => [], 'generatedAt' => '2026-10-07T12:00:00+00:00']],
            'missing generatedAt' => [['text' => 'x', 'facts' => []]],
            'unknown value type' => [
                [
                    'text' => 'x',
                    'facts' => [['k', 'removed_type', 'v', true]],
                    'generatedAt' => '2026-10-07T12:00:00+00:00',
                ],
            ],
            'malformed fact entry' => [
                ['text' => 'x', 'facts' => ['not-a-fact'], 'generatedAt' => '2026-10-07T12:00:00+00:00'],
            ],
            'garbage generatedAt' => [['text' => 'x', 'facts' => [], 'generatedAt' => 'not a date']],
        ];
    }

    private function expectTtl(int $ttl): void
    {
        $this->configManager->expects(self::any())
            ->method('get')
            ->with('oro_smart_brief.cache_ttl')
            ->willReturn($ttl);
    }
}
