<?php

namespace Oro\Bundle\SmartBriefBundle\Placeholder;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryProviderResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Decides whether the SmartBrief placeholder is rendered on a record page: the record must be a
 * persisted entity, eligible for a summary (through the shared resolver), and the current user must be
 * allowed to view it (the first of the two VIEW checks). No fact is computed here.
 */
class SmartBriefPlaceholderFilter
{
    public function __construct(
        private readonly SummaryProviderResolver $providerResolver,
        private readonly DoctrineHelper $doctrineHelper,
        private readonly AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    public function isApplicable(mixed $entity): bool
    {
        return \is_object($entity)
            && $this->doctrineHelper->isManageableEntity($entity)
            && !$this->doctrineHelper->isNewEntity($entity)
            && null !== $this->providerResolver->resolve($entity)
            && $this->authorizationChecker->isGranted('VIEW', $entity);
    }
}
