<?php

namespace Oro\Bundle\AiContentGenerationBundle\Feature\Voter;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\AiContentGenerationBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\Voter\VoterInterface;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides whatever AI Content Generation feature enabled depending on integration status
 */
class FeatureVoter implements VoterInterface
{
    public const FEATURE_NAME = 'oro_ai_content_generation';

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ManagerRegistry $registry,
        private readonly RequestStack $requestStack,
        private array $excludedRoutes
    ) {
    }

    public function vote($feature, $scopeIdentifier = null): int
    {
        if ($feature !== self::FEATURE_NAME || $this->isExcludedPage()) {
            return self::FEATURE_ABSTAIN;
        }

        $transport = $this->getTransport();

        if ($transport?->getChannel()?->isEnabled()) {
            return self::FEATURE_ENABLED;
        }

        return self::FEATURE_DISABLED;
    }

    private function getTransport(): ?Transport
    {
        $transportId = $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::GENERATOR_TYPE_KEY)
        );

        if (!$transportId) {
            return null;
        }

        return $this->registry->getRepository(Transport::class)->find($transportId);
    }

    private function isExcludedPage(): bool
    {
        return in_array(
            $this->requestStack->getCurrentRequest()?->attributes?->get('_route'),
            $this->excludedRoutes
        );
    }
}
