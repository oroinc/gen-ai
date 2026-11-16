<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Doctrine\DBAL\Types\Types;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\EntityBundle\Tools\EntityRoutingHelper;
use Oro\Bundle\SecurityBundle\Metadata\EntitySecurityMetadataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Decides what the current user may see in SmartBrief:
 *
 * - {@see getViewableEntity()}: the record a widget request asks for. An unknown or unsupported class,
 *   a malformed id, a missing record and a record the user may not view are all the same `null`, so the route
 *   reveals nothing about records the user may not see. Class support is decided through the
 *   provider registry, before any record is loaded. A failure while loading the record is logged.
 * - {@see canView()}: the first step of every fact source: the current user must have
 *   class-level VIEW on the source entity; if not, the provider emits none of that source's facts (counts
 *   included). When it is granted, the source query still applies the ACL, so the facts describe only the records
 *   the user can see. The argument is the ACL-protected class of the source (for emails `EmailUser`, not `Email`).
 *   A class without ACL protection is refused: the platform would grant it to everyone, which would silently turn
 *   the check off.
 */
class SummaryAccessProvider
{
    private const string INTEGER_ID_PATTERN = '/^[1-9]\d{0,17}$/';
    private const array INTEGER_ID_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT];

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EntitySecurityMetadataProvider $securityMetadataProvider,
        private readonly EntityRoutingHelper $entityRoutingHelper,
        private readonly SummaryFactProviderRegistry $providerRegistry,
        private readonly DoctrineHelper $doctrineHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function canView(string $entityClass): bool
    {
        return $this->securityMetadataProvider->isProtectedEntity($entityClass)
            && $this->authorizationChecker->isGranted('VIEW', 'entity:' . $entityClass);
    }

    /**
     * Both arguments are user input (the route segments): anything that is not a supported class with
     * a well-formed id is refused before the database is asked (an out-of-range id would abort the request's
     * transaction).
     */
    public function getViewableEntity(string $entityClass, string $entityId): ?object
    {
        try {
            $resolvedClass = $this->entityRoutingHelper->resolveEntityClass($entityClass);
            if (
                null === $this->providerRegistry->getProvider($resolvedClass)
                || !$this->isWellFormedId($resolvedClass, $entityId)
            ) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            $entity = $this->doctrineHelper->getEntity($resolvedClass, $entityId);
        } catch (\Throwable $exception) {
            // Keeps the refusal uniform without hiding an infrastructure failure from the log.
            $this->logger->error('SmartBrief widget could not load the record.', [
                'entity_class' => $resolvedClass,
                'entity_id' => $entityId,
                'exception_class' => $exception::class,
            ]);

            return null;
        }

        return null !== $entity && $this->authorizationChecker->isGranted('VIEW', $entity) ? $entity : null;
    }

    private function isWellFormedId(string $entityClass, string $entityId): bool
    {
        if ('' === $entityId) {
            return false;
        }
        $idType = $this->doctrineHelper->getSingleEntityIdentifierFieldType($entityClass);

        return !\in_array($idType, self::INTEGER_ID_TYPES, true)
            || (bool)preg_match(self::INTEGER_ID_PATTERN, $entityId);
    }
}
