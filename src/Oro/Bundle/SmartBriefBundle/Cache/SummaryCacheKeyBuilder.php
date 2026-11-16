<?php

namespace Oro\Bundle\SmartBriefBundle\Cache;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\LocaleBundle\Manager\LocalizationManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\SmartBriefBundle\DependencyInjection\Configuration;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;

/**
 * Composes the cache key of a summary.
 *
 * The key covers the key-format version, the audience, the record type, class and id, the current organization
 * and the user's back-office localization (0 when there is none), and then either the FactSet fingerprint
 * (refresh-on-change enabled: any data change gives a new summary) or the current user id (disabled: users with
 * different permissions never share an entry). The integration channel, the model and the prompt
 * translations are deliberately not part of it.
 */
class SummaryCacheKeyBuilder
{
    /** Bumped whenever the key parts, the canonical form or the prompt structure change. */
    public const string KEY_FORMAT_VERSION = 'v1';
    public const string AUDIENCE_BACKEND = 'backend';

    private const string KEY_PREFIX = 'oro_smart_brief_';

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly TokenAccessorInterface $tokenAccessor,
        private readonly FactSetSerializer $serializer,
        private readonly LocalizationManager $localizationManager
    ) {
    }

    /**
     * @param int|string $entityId The record's single identifier as Doctrine reports it (integer or UUID/string)
     * @param FactSet|null $facts The facts the summary is built from; required only when refresh-on-change is enabled
     *
     * @throws \LogicException without an authenticated organization/user, or without facts while refresh-on-change
     *                         is enabled
     */
    public function build(
        string $entityType,
        string $entityClass,
        int|string $entityId,
        ?FactSet $facts = null
    ): string {
        $parts = [
            self::KEY_FORMAT_VERSION,
            self::AUDIENCE_BACKEND,
            $entityType,
            $entityClass,
            $entityId,
            $this->getOrganizationId(),
            $this->localizationManager->getDefaultLocalization()?->getId() ?? 0,
            $this->isRefreshOnChangeEnabled() ? $this->getFactsHash($facts) : 'user:' . $this->getUserId(),
        ];

        return self::KEY_PREFIX . hash('sha256', implode('|', $parts));
    }

    private function getFactsHash(?FactSet $facts): string
    {
        if (null === $facts) {
            throw new \LogicException('A summary cache key needs the facts when refresh-on-change is on.');
        }

        return $this->serializer->hash($facts);
    }

    private function getOrganizationId(): int
    {
        $organizationId = $this->tokenAccessor->getOrganizationId();
        if (!$organizationId) {
            throw new \LogicException('A summary cache key needs an authenticated token with an organization.');
        }

        return (int)$organizationId;
    }

    private function getUserId(): int
    {
        $userId = $this->tokenAccessor->getUserId();
        if (!$userId) {
            throw new \LogicException(
                'A summary cache key needs an authenticated user when refresh-on-change is off.'
            );
        }

        return (int)$userId;
    }

    /**
     * Whether the key covers the FactSet fingerprint (true) or the current user (false).
     */
    public function isRefreshOnChangeEnabled(): bool
    {
        return (bool)$this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::CACHE_INVALIDATE_ON_CHANGE_KEY)
        );
    }
}
