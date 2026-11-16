<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactory;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\SummaryTextResult;
use Oro\Bundle\SmartBriefBundle\Prompt\SummaryRequestBuilder;

/**
 * The AI leg of a generation: builds the request from the facts, calls the selected provider's client and tidies
 * the paragraph. A {@see SummaryClientException} from the client factory, the client or the
 * post-processing becomes a fallback result; any other error propagates to the generator.
 */
class SummaryTextGenerator
{
    public function __construct(
        private readonly SummaryRequestBuilder $requestBuilder,
        private readonly SummaryClientFactory $clientFactory,
        private readonly SummaryTextPostProcessorInterface $textPostProcessor
    ) {
    }

    public function generate(string $entityType, FactSet $facts): SummaryTextResult
    {
        $request = $this->requestBuilder->build($entityType, $facts);
        $channelType = null;
        try {
            $client = $this->clientFactory->getClient();
            $channelType = $client->getProviderType();
            $response = $client->generateSummary($request);

            return SummaryTextResult::success(
                $this->textPostProcessor->process($response->text),
                $channelType,
                $response->model,
                $response->inputTokens,
                $response->outputTokens
            );
        } catch (SummaryClientException $exception) {
            return SummaryTextResult::fallback($exception, $channelType);
        }
    }
}
