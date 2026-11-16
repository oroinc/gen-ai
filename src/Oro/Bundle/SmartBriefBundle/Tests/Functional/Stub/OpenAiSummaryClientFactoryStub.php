<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub;

use Oro\Bundle\SmartBriefBundle\Client\OpenAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use Oro\Bundle\SmartBriefBundle\Client\VertexAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactoryInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Replaces both provider client factories in the test container (services_test.yml) and hands out
 * {@see SummaryClientStub} clients driven by the static behaviour a test sets in its setUp(), so no functional
 * test can reach OpenAI or Vertex AI. Of the settings bag only the model name is kept.
 */
final class OpenAiSummaryClientFactoryStub implements SummaryClientFactoryInterface
{
    private static ?SummaryResponse $response = null;
    private static ?SummaryClientException $exception = null;
    private static int $sleepMilliseconds = 0;
    private static ?string $lastModel = null;
    private static ?SummaryClientStub $lastClient = null;

    public static function respondWith(SummaryResponse $response): void
    {
        self::$response = $response;
        self::$exception = null;
    }

    public static function failWith(SummaryClientException $exception): void
    {
        self::$exception = $exception;
    }

    public static function sleepForMs(int $milliseconds): void
    {
        self::$sleepMilliseconds = $milliseconds;
    }

    public static function reset(): void
    {
        self::$response = null;
        self::$exception = null;
        self::$sleepMilliseconds = 0;
        self::$lastModel = null;
        self::$lastClient = null;
    }

    public static function getLastModel(): ?string
    {
        return self::$lastModel;
    }

    public static function getLastClient(): ?SummaryClientStub
    {
        return self::$lastClient;
    }

    #[\Override]
    public function supports(string $channelType): bool
    {
        return \in_array(
            $channelType,
            [OpenAiSummaryClient::PROVIDER_TYPE, VertexAiSummaryClient::PROVIDER_TYPE],
            true
        );
    }

    #[\Override]
    public function build(ParameterBag $transportSettings): SummaryClientInterface
    {
        self::$lastModel = (string)$transportSettings->get('model');

        return self::$lastClient = new SummaryClientStub(self::$response, self::$exception, self::$sleepMilliseconds);
    }
}
