<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Generator;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Generator\GenerationMetrics;
use Oro\Bundle\SmartBriefBundle\Model\SummaryTextResult;
use PHPUnit\Framework\TestCase;

/**
 * The context shape per outcome, token counts only when reported, latency passed through.
 */
final class GenerationMetricsTest extends TestCase
{
    public function testUntouchedAttemptIsHiddenByError(): void
    {
        $metrics = new GenerationMetrics();

        self::assertSame(
            [
                'entity_type' => null,
                'channel_type' => null,
                'model' => null,
                'cache' => 'none',
                'refresh_on_change' => null,
                'latency_ms' => 3,
                'outcome' => 'hidden',
                'hidden_reason' => 'error',
            ],
            $metrics->toContext(3)
        );
    }

    public function testNoActivity(): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('customer');
        $metrics->setRefreshOnChange(true);
        $metrics->markNoActivity();

        self::assertSame(
            [
                'entity_type' => 'customer',
                'channel_type' => null,
                'model' => null,
                'cache' => 'none',
                'refresh_on_change' => true,
                'latency_ms' => 3,
                'outcome' => 'no_activity',
            ],
            $metrics->toContext(3)
        );
    }

    public function testGeneratedSummary(): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('customer');
        $metrics->setRefreshOnChange(true);
        $metrics->markCacheMiss();
        $metrics->markGenerated(SummaryTextResult::success('Text', 'open_ai', 'gpt-5-mini', 120, 40));

        self::assertSame(
            [
                'entity_type' => 'customer',
                'channel_type' => 'open_ai',
                'model' => 'gpt-5-mini',
                'cache' => 'miss',
                'refresh_on_change' => true,
                'latency_ms' => 850,
                'outcome' => 'ok',
                'input_tokens' => 120,
                'output_tokens' => 40,
            ],
            $metrics->toContext(850)
        );
    }

    public function testTokensAreOmittedWhenNotReported(): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('rfq');
        $metrics->setRefreshOnChange(false);
        $metrics->markCacheMiss();
        $metrics->markGenerated(SummaryTextResult::success('Text', 'vertex_ai', 'gemini-2.5-flash', null, null));

        $context = $metrics->toContext(10);

        self::assertArrayNotHasKey('input_tokens', $context);
        self::assertArrayNotHasKey('output_tokens', $context);
        self::assertSame('ok', $context['outcome']);
    }

    public function testCacheHit(): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('customer');
        $metrics->setRefreshOnChange(false);
        $metrics->markCacheHit();

        self::assertSame(
            [
                'entity_type' => 'customer',
                'channel_type' => null,
                'model' => null,
                'cache' => 'hit',
                'refresh_on_change' => false,
                'latency_ms' => 2,
                'outcome' => 'ok',
            ],
            $metrics->toContext(2)
        );
    }

    public function testFallback(): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('customer');
        $metrics->setRefreshOnChange(true);
        $metrics->markCacheMiss();
        $exception = new SummaryClientException(
            'body with the prompt',
            SummaryClientException::REASON_RATE_LIMITED,
            429,
            'rate_limit_exceeded'
        );
        $metrics->markGenerated(SummaryTextResult::fallback($exception, 'open_ai'));

        self::assertSame(
            [
                'entity_type' => 'customer',
                'channel_type' => 'open_ai',
                'model' => null,
                'cache' => 'miss',
                'refresh_on_change' => true,
                'latency_ms' => 5000,
                'outcome' => 'fallback',
                'fallback_reason' => 'rate_limited',
                'http_status' => 429,
            ],
            $metrics->toContext(5000)
        );
    }

    /**
     * @dataProvider hiddenReasonDataProvider
     */
    public function testHidden(string $reason): void
    {
        $metrics = new GenerationMetrics();
        $metrics->setEntityType('customer');
        $metrics->markHidden($reason);

        $context = $metrics->toContext(1);

        self::assertSame('hidden', $context['outcome']);
        self::assertSame($reason, $context['hidden_reason']);
        self::assertArrayNotHasKey('fallback_reason', $context);
    }

    public function testUnknownHiddenReasonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new GenerationMetrics())->markHidden('prompt text');
    }

    public static function hiddenReasonDataProvider(): array
    {
        return [
            [GenerationMetrics::HIDDEN_FEATURE_OFF],
            [GenerationMetrics::HIDDEN_SWITCH_OFF],
            [GenerationMetrics::HIDDEN_NO_PROVIDER],
            [GenerationMetrics::HIDDEN_ERROR],
        ];
    }
}
