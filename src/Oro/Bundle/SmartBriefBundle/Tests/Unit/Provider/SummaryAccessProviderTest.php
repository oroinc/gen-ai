<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\EntityBundle\Tools\EntityRoutingHelper;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\SecurityBundle\Metadata\EntitySecurityMetadataProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderRegistry;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\CustomerStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Covers the class-level VIEW check (on the `entity:` descriptor, refused for classes without ACL protection) and
 * the refusals of the widget record that the functional test cannot provoke (a throwing provider check,
 * a throwing record load, malformed ids).
 */
final class SummaryAccessProviderTest extends TestCase
{
    private const string CLASS_ALIAS = 'Acme_Customer';

    private AuthorizationCheckerInterface&MockObject $authorizationChecker;
    private EntitySecurityMetadataProvider&MockObject $securityMetadataProvider;
    private EntityRoutingHelper&MockObject $routingHelper;
    private SummaryFactProviderRegistry&MockObject $providerRegistry;
    private DoctrineHelper&MockObject $doctrineHelper;
    private LoggerInterface&MockObject $logger;
    private CustomerStub $entity;
    private SummaryAccessProvider $provider;

    #[\Override]
    protected function setUp(): void
    {
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->securityMetadataProvider = $this->createMock(EntitySecurityMetadataProvider::class);
        $this->routingHelper = $this->createMock(EntityRoutingHelper::class);
        $this->providerRegistry = $this->createMock(SummaryFactProviderRegistry::class);
        $this->doctrineHelper = $this->createMock(DoctrineHelper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entity = new CustomerStub();

        $this->provider = new SummaryAccessProvider(
            $this->authorizationChecker,
            $this->securityMetadataProvider,
            $this->routingHelper,
            $this->providerRegistry,
            $this->doctrineHelper,
            $this->logger
        );
    }

    /**
     * @dataProvider grantedDataProvider
     */
    public function testCanViewAProtectedClass(bool $granted): void
    {
        $this->securityMetadataProvider->expects(self::once())
            ->method('isProtectedEntity')
            ->with(Order::class)
            ->willReturn(true);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('VIEW', 'entity:' . Order::class)
            ->willReturn($granted);

        self::assertSame($granted, $this->provider->canView(Order::class));
    }

    public static function grantedDataProvider(): array
    {
        return ['granted' => [true], 'denied' => [false]];
    }

    public function testClassWithoutAclProtectionIsRefused(): void
    {
        $this->securityMetadataProvider->expects(self::once())
            ->method('isProtectedEntity')
            ->with(Email::class)
            ->willReturn(false);
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertFalse($this->provider->canView(Email::class));
    }

    public function testViewableEntityIsReturned(): void
    {
        $this->expectSupportedClass();
        $this->doctrineHelper->expects(self::once())
            ->method('getEntity')
            ->with(CustomerStub::class, '42')
            ->willReturn($this->entity);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('VIEW', self::identicalTo($this->entity))
            ->willReturn(true);

        self::assertSame($this->entity, $this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    public function testUnsupportedClassIsRefusedBeforeTheRecordIsLoaded(): void
    {
        $this->routingHelper->expects(self::once())
            ->method('resolveEntityClass')
            ->with(self::CLASS_ALIAS)
            ->willReturn(CustomerStub::class);
        $this->providerRegistry->expects(self::once())->method('getProvider')->willReturn(null);
        $this->doctrineHelper->expects(self::never())->method('getEntity');

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    public function testThrowingProviderCheckIsARefusalWithoutALog(): void
    {
        $this->routingHelper->expects(self::once())
            ->method('resolveEntityClass')
            ->with(self::CLASS_ALIAS)
            ->willReturn(CustomerStub::class);
        $this->providerRegistry->expects(self::once())
            ->method('getProvider')
            ->willThrowException(new \ReflectionException('Class "Acme\Nope" does not exist'));
        $this->doctrineHelper->expects(self::never())->method('getEntity');
        $this->logger->expects(self::never())->method('error');

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    public function testRecordLoadFailureIsLoggedAndRefused(): void
    {
        $this->expectSupportedClass();
        $this->doctrineHelper->expects(self::once())
            ->method('getEntity')
            ->with(CustomerStub::class, '42')
            ->willThrowException(new \RuntimeException('SQLSTATE[08006] connection to server failed'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief widget could not load the record.', [
                'entity_class' => CustomerStub::class,
                'entity_id' => '42',
                'exception_class' => \RuntimeException::class,
            ]);
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    public function testMissingRecordIsRefused(): void
    {
        $this->expectSupportedClass();
        $this->doctrineHelper->expects(self::once())->method('getEntity')->willReturn(null);
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    public function testViewDeniedIsTheSameRefusal(): void
    {
        $this->expectSupportedClass();
        $this->doctrineHelper->expects(self::once())->method('getEntity')->willReturn($this->entity);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('VIEW', self::identicalTo($this->entity))
            ->willReturn(false);

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, '42'));
    }

    /**
     * @dataProvider malformedIdDataProvider
     */
    public function testMalformedIdIsRefusedBeforeTheDatabaseIsAsked(string $idType, string $entityId): void
    {
        $this->expectSupportedClass($idType);
        $this->doctrineHelper->expects(self::never())->method('getEntity');
        $this->logger->expects(self::never())->method('error');

        self::assertNull($this->provider->getViewableEntity(self::CLASS_ALIAS, $entityId));
    }

    public static function malformedIdDataProvider(): array
    {
        return [
            'text for an integer id' => ['integer', 'not-an-id'],
            'leading zero' => ['integer', '042'],
            'negative' => ['bigint', '-1'],
            'out of range' => ['integer', '99999999999999999999999'],
            'empty for a string id' => ['guid', ''],
        ];
    }

    public function testStringIdIsPassedThroughForAStringKeyedEntity(): void
    {
        $this->expectSupportedClass('guid');
        $this->doctrineHelper->expects(self::once())
            ->method('getEntity')
            ->with(CustomerStub::class, '0f8fad5b-d9cb-469f-a165-70867728950e')
            ->willReturn($this->entity);
        $this->authorizationChecker->expects(self::once())->method('isGranted')->willReturn(true);

        self::assertSame(
            $this->entity,
            $this->provider->getViewableEntity(self::CLASS_ALIAS, '0f8fad5b-d9cb-469f-a165-70867728950e')
        );
    }

    private function expectSupportedClass(string $idType = 'integer'): void
    {
        $this->routingHelper->expects(self::once())
            ->method('resolveEntityClass')
            ->with(self::CLASS_ALIAS)
            ->willReturn(CustomerStub::class);
        $this->providerRegistry->expects(self::once())
            ->method('getProvider')
            ->with(CustomerStub::class)
            ->willReturn($this->createMock(SummaryFactProviderInterface::class));
        $this->doctrineHelper->expects(self::any())
            ->method('getSingleEntityIdentifierFieldType')
            ->with(CustomerStub::class)
            ->willReturn($idType);
    }
}
