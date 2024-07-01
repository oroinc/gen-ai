<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\DependencyInjection\Configuration;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureCheckerAwareInterface;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureCheckerHolderTrait;
use Oro\Bundle\IntegrationBundle\Entity\Channel;

/**
 * Builds AI Content Generation Client
 */
class ContentGenerationClientFactory implements FeatureCheckerAwareInterface
{
    use FeatureCheckerHolderTrait;

    /**
     * @param iterable<ContentGenerationClientFactoryInterface> $clientFactories
     */
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ManagerRegistry $registry,
        private readonly iterable $clientFactories
    ) {
    }

    /**
     * @throws ContentGenerationClientException
     */
    public function getClient(Channel $channel): ContentGenerationClientInterface
    {
        $type = $channel->getType();

        if (!$type || !$channel->getTransport()) {
            $this->throwTypeNotSupportedAiGenerationException();
        }

        foreach ($this->clientFactories as $clientFactory) {
            if ($clientFactory->supports($type)) {
                return $clientFactory->build($channel->getTransport()->getSettingsBag());
            }
        }

        $this->throwTypeNotSupportedAiGenerationException();
    }

    public function getActivatedClient(): ContentGenerationClientInterface
    {
        if (!$this->isFeaturesEnabled()) {
            $this->throwNoActivatedAiGenerationException();
        }

        return $this->getClient($this->getChannel());
    }

    private function getChannel(): Channel
    {
        $channelId = $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::GENERATOR_TYPE_KEY)
        );

        return $this->registry->getRepository(Channel::class)->find($channelId);
    }

    private function throwNoActivatedAiGenerationException(): void
    {
        throw new ContentGenerationClientException(
            'There is no activated Content AI Generation Client'
        );
    }

    private function throwTypeNotSupportedAiGenerationException(): void
    {
        throw new ContentGenerationClientException(
            'There is no valid integration type for Content AI Generation Client'
        );
    }
}
