<?php

namespace Oro\Bundle\SmartBriefBundle\Factory;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Builds a summary client from the settings of an integration channel of a supported type.
 *
 * Implementations are tagged with `oro_smart_brief.client_factory`; the dispatcher picks the first one
 * whose {@see supports()} accepts the channel type. Order follows the tag `priority` (higher first), so a project
 * factory can take precedence over a built-in one for the same channel type.
 */
interface SummaryClientFactoryInterface
{
    /**
     * @param string $channelType Integration channel type, e.g. 'open_ai' or 'vertex_ai'
     */
    public function supports(string $channelType): bool;

    /**
     * @param ParameterBag $transportSettings The channel transport's settings bag. It contains the provider
     *                                        credentials: implementations must not keep the bag beyond
     *                                        this call and must name only the missing setting key, never a value,
     *                                        in an exception message.
     *
     * @throws SummaryClientException when required settings are missing or credentials cannot be prepared
     */
    public function build(ParameterBag $transportSettings): SummaryClientInterface;
}
