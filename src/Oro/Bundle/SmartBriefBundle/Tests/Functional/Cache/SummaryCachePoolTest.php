<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Cache;

use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCache;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The pool service exists, the summary cache round-trips through it,
 * and clearing the pool removes every summary.
 */
final class SummaryCachePoolTest extends WebTestCase
{
    private CacheItemPoolInterface $pool;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient();
        $this->pool = self::getContainer()->get('oro_smart_brief.tests.cache');
        $this->pool->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->pool->clear();
        parent::tearDown();
    }

    public function testPoolRoundTripAndClear(): void
    {
        self::assertInstanceOf(CacheItemPoolInterface::class, $this->pool);

        /** @var SummaryCache $cache */
        $cache = self::getContainer()->get('oro_smart_brief.tests.cache.summary');
        $facts = new FactSet();
        $facts->add(new Fact('last_order_total', FactValueType::MONEY, Price::create('12480', 'USD')));
        $facts->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));

        $cache->save('oro_smart_brief_test', new SummaryView('Summary.', $facts, false, new \DateTimeImmutable()));
        $cached = $cache->get('oro_smart_brief_test');

        self::assertNotNull($cached);
        self::assertSame('Summary.', $cached->text);
        self::assertEquals(Price::create('12480.0000', 'USD'), iterator_to_array($cached->facts)[0]->value);
        self::assertFalse(iterator_to_array($cached->facts)[1]->informative);

        self::assertTrue($this->pool->clear());
        self::assertNull($cache->get('oro_smart_brief_test'));
    }
}
