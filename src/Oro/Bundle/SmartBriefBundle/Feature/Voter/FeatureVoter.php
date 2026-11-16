<?php

namespace Oro\Bundle\SmartBriefBundle\Feature\Voter;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\Voter\VoterInterface;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Provider\SelectedIntegrationChannelProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides whether the SmartBrief feature is enabled.
 *
 * - When the feature toggle is off, the feature is disabled.
 * - The feature also needs a selected, enabled integration channel that writes the AI summary.
 * - On the system configuration pages the voter abstains, so the settings stay reachable while the feature is off.
 */
class FeatureVoter implements VoterInterface
{
    public const string FEATURE_NAME = 'oro_smart_brief';

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly SelectedIntegrationChannelProvider $channelProvider,
        private readonly RequestStack $requestStack,
        private readonly array $excludedRoutes
    ) {
    }

    #[\Override]
    public function vote($feature, $scopeIdentifier = null): int
    {
        if ($feature !== self::FEATURE_NAME || $this->isExcludedPage()) {
            return self::FEATURE_ABSTAIN;
        }

        $enabled = $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::ENABLED_KEY),
            false,
            false,
            $scopeIdentifier
        );
        if (!$enabled) {
            return self::FEATURE_DISABLED;
        }
        return $this->channelProvider->getChannel($scopeIdentifier)?->isEnabled()
            ? self::FEATURE_ENABLED
            : self::FEATURE_DISABLED;
    }

    private function isExcludedPage(): bool
    {
        return \in_array(
            $this->requestStack->getCurrentRequest()?->attributes->get('_route'),
            $this->excludedRoutes,
            true
        );
    }
}
