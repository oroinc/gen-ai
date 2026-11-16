<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use PHPUnit\Framework\TestCase;

/**
 * Value round-trip, null token defaults and immutability of the response type.
 */
final class SummaryResponseTest extends TestCase
{
    public function testTokenCountsDefaultToNull(): void
    {
        $response = new SummaryResponse('text', 'gpt-5-mini');

        self::assertSame('text', $response->text);
        self::assertSame('gpt-5-mini', $response->model);
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
    }

    public function testValuesRoundTrip(): void
    {
        $response = new SummaryResponse('text', 'gpt-5-mini', 812, 64);

        self::assertSame(812, $response->inputTokens);
        self::assertSame(64, $response->outputTokens);
    }

    public function testIsImmutable(): void
    {
        $reflection = new \ReflectionClass(SummaryResponse::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
