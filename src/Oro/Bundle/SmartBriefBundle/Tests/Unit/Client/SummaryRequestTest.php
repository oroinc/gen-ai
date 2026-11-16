<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use PHPUnit\Framework\TestCase;

/**
 * Value round-trip and immutability of the request type.
 */
final class SummaryRequestTest extends TestCase
{
    public function testValuesRoundTrip(): void
    {
        $request = new SummaryRequest(
            systemPrompt: 'system',
            userContent: '<facts></facts>',
            languageCode: 'de',
            temperature: 0.2,
            maxOutputTokens: 1024
        );

        self::assertSame('system', $request->systemPrompt);
        self::assertSame('<facts></facts>', $request->userContent);
        self::assertSame('de', $request->languageCode);
        self::assertSame(0.2, $request->temperature);
        self::assertSame(1024, $request->maxOutputTokens);
    }

    public function testIsImmutable(): void
    {
        $reflection = new \ReflectionClass(SummaryRequest::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
