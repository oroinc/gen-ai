<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;

/**
 * Loads the integration channel selected in `oro_smart_brief.integration` for the given configuration scope;
 * the one lookup the feature voter and the client dispatcher share.
 *
 * The channel must belong to the organization the summary is made for: the organization of the scope
 * (an organization, or a scope object such as a website), otherwise the current user's organization. A channel of
 * another organization — selected by a global-access admin, written by CLI/import, or an application-level value
 * inherited by every organization — is treated as "no integration", so one organization's facts never go through
 * another organization's AI account.
 */
class SelectedIntegrationChannelProvider
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ManagerRegistry $doctrine,
        private readonly TokenAccessorInterface $tokenAccessor
    ) {
    }

    public function getChannelId(object|int|null $scopeIdentifier = null): ?int
    {
        $channelId = (int)$this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::INTEGRATION_KEY),
            false,
            false,
            $scopeIdentifier
        );

        return $channelId > 0 ? $channelId : null;
    }

    public function getChannel(object|int|null $scopeIdentifier = null): ?Channel
    {
        $channelId = $this->getChannelId($scopeIdentifier);
        if (null === $channelId) {
            return null;
        }

        $channel = $this->doctrine->getRepository(Channel::class)->find($channelId);
        if (!$channel instanceof Channel) {
            return null;
        }
        $organizationId = $this->getOrganizationId($scopeIdentifier);

        return null !== $organizationId && $channel->getOrganization()?->getId() === $organizationId ? $channel : null;
    }

    private function getOrganizationId(object|int|null $scopeIdentifier): ?int
    {
        if ($scopeIdentifier instanceof Organization) {
            return $scopeIdentifier->getId();
        }
        if (\is_object($scopeIdentifier) && method_exists($scopeIdentifier, 'getOrganization')) {
            return $scopeIdentifier->getOrganization()?->getId();
        }

        return $this->tokenAccessor->getOrganizationId();
    }
}
