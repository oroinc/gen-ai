<?php

namespace Oro\Bundle\SmartBriefBundle\Factory;

use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SelectedIntegrationChannelProvider;

/**
 * Builds the summary client for the integration channel selected in system configuration.
 *
 * The channel is loaded at use time (it may have changed since the feature voter looked at it) and handed to
 * the first tagged client factory that supports its type. Every problem — no selection, a missing, disabled
 * or transport-less channel, an unsupported type — is a {@see SummaryClientException}, so the generator falls
 * back to the facts. Exceptions thrown by the built client pass through unchanged. Messages name the
 * channel id and type only.
 */
class SummaryClientFactory
{
    /**
     * @param iterable<SummaryClientFactoryInterface> $factories
     */
    public function __construct(
        private readonly SelectedIntegrationChannelProvider $channelProvider,
        private readonly iterable $factories
    ) {
    }

    /**
     * @throws SummaryClientException
     */
    public function getClient(): SummaryClientInterface
    {
        $channel = $this->getChannel();

        $transport = $channel->getTransport();
        if (null === $transport) {
            throw new SummaryClientException(
                sprintf('The AI integration channel #%d has no transport settings.', $channel->getId())
            );
        }

        foreach ($this->factories as $factory) {
            if ($factory->supports((string)$channel->getType())) {
                return $factory->build($transport->getSettingsBag());
            }
        }

        throw new SummaryClientException(
            sprintf('No summary client supports the integration type "%s".', $channel->getType())
        );
    }

    private function getChannel(): Channel
    {
        $channel = $this->channelProvider->getChannel();
        if (null === $channel) {
            $channelId = $this->channelProvider->getChannelId();
            if (null === $channelId) {
                throw new SummaryClientException('No AI integration is selected for summaries.');
            }

            throw new SummaryClientException(
                sprintf(
                    'The AI integration channel #%d selected for summaries does not exist in this organization.',
                    $channelId
                )
            );
        }
        $channelId = $channel->getId();
        if (!$channel->isEnabled()) {
            throw new SummaryClientException(
                sprintf('The AI integration channel #%d selected for summaries is disabled.', $channelId)
            );
        }

        return $channel;
    }
}
