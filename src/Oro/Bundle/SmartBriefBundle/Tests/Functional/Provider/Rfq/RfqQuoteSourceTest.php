<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Rfq;

use Oro\Bundle\SaleBundle\Entity\Quote;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadRfqQuoteData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\QueryCountTrait;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * Covers the RFQ quote facts on live quotes, through the RFQ provider.
 */
final class RfqQuoteSourceTest extends WebTestCase
{
    use QueryCountTrait;

    private const array QUOTE_KEYS = ['active_quote_count', 'quote_for_this_rfq', 'quote_for_this_rfq_status'];

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadRfqQuoteData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    /**
     * @dataProvider rfqDataProvider
     */
    public function testQuoteFacts(string $rfq, array $expected): void
    {
        self::assertSame($expected, $this->getQuoteFacts($rfq));
    }

    public static function rfqDataProvider(): array
    {
        $status = static fn (string $id) => 'quote_internal_status.' . $id;

        return [
            'the newest linked quote, accepted included; accepted not counted' => [
                LoadRfqQuoteData::RFQ_TWO_LINKED,
                [
                    'active_quote_count' => 3,
                    'quote_for_this_rfq' => '45',
                    'quote_for_this_rfq_status' => $status('accepted'),
                ],
            ],
            'a newer declined quote and another customer\'s quote are ignored' => [
                LoadRfqQuoteData::RFQ_SENT,
                [
                    'active_quote_count' => 3,
                    'quote_for_this_rfq' => '42',
                    'quote_for_this_rfq_status' => $status('sent_to_customer'),
                ],
            ],
            'quotes with the expired flag or expired earlier today are not live' => [
                LoadRfqQuoteData::RFQ_EXPIRED_ONLY,
                ['active_quote_count' => 3],
            ],
            'a quote without status is live and linked, with no status fact' => [
                LoadRfqQuoteData::RFQ_NO_STATUS,
                ['active_quote_count' => 3, 'quote_for_this_rfq' => '70'],
            ],
            'same creation time: the higher id wins' => [
                LoadRfqQuoteData::RFQ_TIE,
                [
                    'active_quote_count' => 3,
                    'quote_for_this_rfq' => '72',
                    'quote_for_this_rfq_status' => $status('draft'),
                ],
            ],
            'no live quotes: a zero count only' => [
                LoadRfqQuoteData::RFQ_NO_QUOTES,
                ['active_quote_count' => 0],
            ],
        ];
    }

    /**
     * The active quote count, their QIDs to link to, and the quote for this RFQ.
     */
    public function testThreeQueriesWhateverTheNumberOfQuotes(): void
    {
        $source = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq.quote_source');
        $rfq = $this->getReference(LoadRfqQuoteData::RFQ_TWO_LINKED);
        $customer = $rfq->getCustomer();
        // warm up metadata and ACL caches so only the source's own queries are counted
        $source->addFacts(new FactSet(), $rfq, $customer);

        self::assertSame(
            3,
            $this->countQueries(Quote::class, static fn () => $source->addFacts(new FactSet(), $rfq, $customer))
        );
    }

    /**
     * @return array<string, mixed> the quote facts by key
     */
    private function getQuoteFacts(string $rfq): array
    {
        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.rfq')
            ->getFacts($this->getReference($rfq));
        $values = [];
        foreach ($facts as $fact) {
            if (\in_array($fact->key, self::QUOTE_KEYS, true)) {
                $values[$fact->key] = $fact->value;
            }
        }

        return $values;
    }
}
