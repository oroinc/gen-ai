<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form;

use Oro\Bundle\AiContentGenerationBundle\Form\EntityFormResolver;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationAwareInterface;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationInterface;
use Oro\Bundle\OrganizationProBundle\Exception\OrganizationAwareException;
use Oro\Bundle\SecurityBundle\Authentication\Token\OrganizationAwareTokenInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class EntityFormResolverTest extends TestCase
{
    private FormFactoryInterface&MockObject $formFactory;

    private TokenStorageInterface&MockObject $tokenStorage;

    private FormInterface&MockObject $form;

    private EntityFormResolver$entityFormResolver;

    protected function setUp(): void
    {
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->form = $this->createMock(FormInterface::class);

        $this->entityFormResolver = new EntityFormResolver($this->formFactory, $this->tokenStorage);
    }

    public function testResolveSuccessfully(): void
    {
        $formTypeClass = 'SomeFormType';
        $entity = new \StdClass();
        $entityData = ['field' => 'value'];

        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with($formTypeClass, $entity)
            ->willReturn($this->form);

        $this->form
            ->expects(self::once())
            ->method('submit')
            ->with($entityData);

        $this->form
            ->expects(self::once())
            ->method('getData')
            ->willReturn($entity);

        $result = $this->entityFormResolver->resolve($formTypeClass, $entity, $entityData);

        self::assertSame($entity, $result);
    }

    public function testResolveHandlesOrganizationAwareException(): void
    {
        $formTypeClass = 'SomeFormType';
        $entity = $this->createMock(OrganizationAwareInterface::class);
        $entityData = ['field' => 'value'];
        $organization = $this->createMock(OrganizationInterface::class);
        $processedEntity = new \StdClass();

        $this->formFactory
            ->expects(self::exactly(2))
            ->method('create')
            ->with($formTypeClass, $entity)
            ->willReturnOnConsecutiveCalls(
                self::throwException(new OrganizationAwareException()),
                $this->form
            );

        $token = $this->createMock(OrganizationAwareTokenInterface::class);
        $token
            ->method('getOrganization')
            ->willReturn($organization);

        $this->tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $entity
            ->expects(self::once())
            ->method('setOrganization')
            ->with($organization);

        $this->form
            ->expects(self::once())
            ->method('submit')
            ->with($entityData);

        $this->form
            ->expects(self::once())
            ->method('getData')
            ->willReturn($processedEntity);

        $result = $this->entityFormResolver->resolve($formTypeClass, $entity, $entityData);

        self::assertSame($processedEntity, $result);
    }

    public function testResolveHandlesExceptionWithoutOrganizationAwareToken(): void
    {
        $formTypeClass = 'SomeFormType';
        $entity = $this->createMock(OrganizationAwareInterface::class);
        $entityData = ['field' => 'value'];
        $exception = new OrganizationAwareException();

        $this->formFactory
            ->expects(self::once())
            ->method('create')
            ->with($formTypeClass, $entity)
            ->willReturnOnConsecutiveCalls(
                self::throwException($exception),
                $this->form
            );

        $this->tokenStorage
            ->expects(self::once())
            ->method('getToken')
            ->willReturn($this->createMock(TokenInterface::class));

        self::expectExceptionObject($exception);

        $this->entityFormResolver->resolve($formTypeClass, $entity, $entityData);
    }
}
