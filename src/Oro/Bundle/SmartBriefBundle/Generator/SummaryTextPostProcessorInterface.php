<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;

/**
 * Tidies the AI paragraph before it is cached and shown. A project replaces the service to apply its
 * own rules.
 */
interface SummaryTextPostProcessorInterface
{
    /**
     * @throws SummaryClientException when nothing usable is left
     */
    public function process(string $text): string;
}
