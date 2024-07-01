<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form;

use Oro\Bundle\OrganizationBundle\Entity\OrganizationAwareInterface;
use Oro\Bundle\OrganizationProBundle\Exception\OrganizationAwareException;
use Oro\Bundle\SecurityBundle\Authentication\Token\OrganizationAwareTokenInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Submits form to get filled up new entity based on provided form data and form type class
 */
class EntityFormResolver
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly TokenStorageInterface $tokenStorage
    ) {
    }

    public function resolve(string $formTypeClass, object $entity, array $entityData): object
    {
        try {
            return $this->submitEntity($formTypeClass, $entity, $entityData);
        } catch (OrganizationAwareException $exception) {
            $token = $this->tokenStorage->getToken();

            if ($token instanceof OrganizationAwareTokenInterface) {
                /**
                 * @var OrganizationAwareInterface $entity
                 */
                $entity->setOrganization($token->getOrganization());

                return $this->submitEntity($formTypeClass, $entity, $entityData);
            }

            throw $exception;
        }
    }

    private function submitEntity(string $formTypeClass, object $entity, array $entityData): object
    {
        $form = $this->formFactory->create($formTypeClass, $entity);

        $form->submit($entityData);

        return $form->getData();
    }
}
