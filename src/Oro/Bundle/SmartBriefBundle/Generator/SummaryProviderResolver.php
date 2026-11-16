<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Doctrine\Common\Util\ClassUtils;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\Feature\Voter\FeatureVoter;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderRegistry;

/**
 * The single owner of the eligibility rule: a record gets a summary only when the feature is on,
 * a provider supports its class and that provider's own switch is on. The generator and the placeholder filter
 * decide through this service; the widget controller decides class support through the registry (an
 * unsupported class is a 404) and eligibility through the generator (a switched-off type is a hidden
 * block, not a 404).
 */
class SummaryProviderResolver
{
    public function __construct(
        private readonly FeatureChecker $featureChecker,
        private readonly SummaryFactProviderRegistry $providerRegistry,
        private readonly ConfigManager $configManager
    ) {
    }

    public function resolve(object $entity): ?SummaryFactProviderInterface
    {
        return $this->resolveForClass(ClassUtils::getClass($entity));
    }

    /**
     * The provider that supports the class regardless of the feature and the switch (null when none does).
     */
    public function findProvider(string $entityClass): ?SummaryFactProviderInterface
    {
        return $this->providerRegistry->getProvider($entityClass);
    }

    /**
     * Why {@see resolveForClass()} returns null, as a {@see GenerationMetrics} hidden reason:
     * `feature_off`, `no_provider` or `switch_off`; null when the class is eligible.
     */
    public function getIneligibilityReason(string $entityClass): ?string
    {
        if (!$this->featureChecker->isFeatureEnabled(FeatureVoter::FEATURE_NAME)) {
            return GenerationMetrics::HIDDEN_FEATURE_OFF;
        }
        $provider = $this->providerRegistry->getProvider($entityClass);
        if (null === $provider) {
            return GenerationMetrics::HIDDEN_NO_PROVIDER;
        }

        return $this->configManager->get($provider->getEnabledConfigKey())
            ? null
            : GenerationMetrics::HIDDEN_SWITCH_OFF;
    }

    /**
     * Decides from the class name alone, so a caller can reject an unsupported class before loading the record.
     */
    public function resolveForClass(string $entityClass): ?SummaryFactProviderInterface
    {
        if (!$this->featureChecker->isFeatureEnabled(FeatureVoter::FEATURE_NAME)) {
            return null;
        }

        $provider = $this->providerRegistry->getProvider($entityClass);
        if (null === $provider || !$this->configManager->get($provider->getEnabledConfigKey())) {
            return null;
        }

        return $provider;
    }
}
