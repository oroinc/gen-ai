<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub;

use Oro\Bundle\SmartBriefBundle\Client\OpenAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;

/**
 * The AI provider stand-in of the test environment: returns a fixed response, sleeps, or throws as the test
 * configured through {@see OpenAiSummaryClientFactoryStub}; never opens a network connection.
 */
final class SummaryClientStub implements SummaryClientInterface
{
    /** @var SummaryRequest[] */
    private array $requests = [];

    public function __construct(
        private readonly ?SummaryResponse $response,
        private readonly ?SummaryClientException $exception,
        private readonly int $sleepMilliseconds
    ) {
    }

    #[\Override]
    public function generateSummary(SummaryRequest $request): SummaryResponse
    {
        $this->requests[] = $request;
        if ($this->sleepMilliseconds > 0) {
            usleep($this->sleepMilliseconds * 1000);
        }
        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->response ?? new SummaryResponse('Stub summary.', 'stub');
    }

    #[\Override]
    public function getProviderType(): string
    {
        return OpenAiSummaryClient::PROVIDER_TYPE;
    }

    /**
     * Fails with the configured exception whatever its reason: in Phase 1 nothing calls it, so the stub
     * keeps one behaviour slot for both methods.
     */
    #[\Override]
    public function checkConnection(): void
    {
        if (null !== $this->exception) {
            throw $this->exception;
        }
    }

    /**
     * @return SummaryRequest[]
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
