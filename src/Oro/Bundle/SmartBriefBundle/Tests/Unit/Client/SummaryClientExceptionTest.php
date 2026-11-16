<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use PHPUnit\Framework\TestCase;

/**
 * Default reason, null status/code, full value round-trip and the reason vocabulary.
 */
final class SummaryClientExceptionTest extends TestCase
{
    public function testDefaults(): void
    {
        $exception = new SummaryClientException('failed');

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('failed', $exception->getMessage());
        self::assertSame('client_error', $exception->getReason());
        self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());
        self::assertNull($exception->getHttpStatus());
        self::assertNull($exception->getProviderErrorCode());
        self::assertNull($exception->getPrevious());
    }

    public function testValuesRoundTrip(): void
    {
        $previous = new \RuntimeException('transport');
        $exception = new SummaryClientException(
            'rate limited',
            'rate_limited',
            429,
            'rate_limit_exceeded',
            $previous
        );

        self::assertSame('rate limited', $exception->getMessage());
        self::assertSame(SummaryClientException::REASON_RATE_LIMITED, $exception->getReason());
        self::assertSame(429, $exception->getHttpStatus());
        self::assertSame('rate_limit_exceeded', $exception->getProviderErrorCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testReasonVocabulary(): void
    {
        self::assertSame(
            ['client_error', 'timeout', 'rate_limited', 'truncated'],
            SummaryClientException::REASONS
        );
    }
}
