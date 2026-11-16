<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

/**
 * Provider-neutral AI client that turns a summary request into a summary response.
 *
 * Implementations know nothing about the summarized entity; they only send the prompt they are given.
 */
interface SummaryClientInterface
{
    /**
     * @throws SummaryClientException on missing credentials, transport errors, timeouts, non-2xx responses,
     *                                empty or truncated output
     */
    public function generateSummary(SummaryRequest $request): SummaryResponse;

    /**
     * Returns the integration channel type, e.g. 'open_ai' or 'vertex_ai' (used in logs and metrics).
     */
    public function getProviderType(): string;

    /**
     * @throws SummaryClientException when the provider cannot be reached with the configured credentials
     */
    public function checkConnection(): void;
}
