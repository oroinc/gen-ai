<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

/**
 * Finds the fact provider for an entity class among the services tagged `oro_smart_brief.fact_provider`.
 *
 * Providers arrive ordered by tag priority (highest first); the first one that supports the class wins,
 * so a project replaces a built-in provider by registering one with a higher priority.
 */
class SummaryFactProviderRegistry
{
    /**
     * @param iterable<SummaryFactProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers
    ) {
    }

    public function getProvider(string $entityClass): ?SummaryFactProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supportsClass($entityClass)) {
                return $provider;
            }
        }

        return null;
    }
}
