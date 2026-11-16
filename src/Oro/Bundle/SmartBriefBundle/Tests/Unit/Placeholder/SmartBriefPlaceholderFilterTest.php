<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Placeholder;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryProviderResolver;
use Oro\Bundle\SmartBriefBundle\Placeholder\SmartBriefPlaceholderFilter;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\CustomerStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Covers the placeholder filter: eligibility through the resolver and VIEW on the record.
 */
final class SmartBriefPlaceholderFilterTest extends TestCase
{
    private SummaryProviderResolver&MockObject $providerResolver;
    private DoctrineHelper&MockObject $doctrineHelper;
    private AuthorizationCheckerInterface&MockObject $authorizationChecker;
    private SmartBriefPlaceholderFilter $filter;

    #[\Override]
    protected function setUp(): void
    {
        $this->providerResolver = $this->createMock(SummaryProviderResolver::class);
        $this->doctrineHelper = $this->createMock(DoctrineHelper::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);

        $this->filter = new SmartBriefPlaceholderFilter(
            $this->providerResolver,
            $this->doctrineHelper,
            $this->authorizationChecker
        );
    }

    /**
     * @dataProvider nonObjectDataProvider
     */
    public function testNonObjectIsNotApplicableWithoutAnyCheck(mixed $entity): void
    {
        $this->providerResolver->expects(self::never())->method('resolve');
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertFalse($this->filter->isApplicable($entity));
    }

    public static function nonObjectDataProvider(): array
    {
        return ['null' => [null], 'string' => ['Oro\Bundle\CustomerBundle\Entity\Customer'], 'int' => [1]];
    }

    /**
     * @dataProvider notPersistedDataProvider
     */
    public function testNotPersistedObjectIsNotApplicableWithoutEligibilityOrAclChecks(
        bool $manageable,
        bool $new
    ): void {
        $entity = new CustomerStub();
        $this->doctrineHelper->expects(self::once())->method('isManageableEntity')->willReturn($manageable);
        $this->doctrineHelper->expects(self::exactly($manageable ? 1 : 0))->method('isNewEntity')->willReturn($new);
        $this->providerResolver->expects(self::never())->method('resolve');
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertFalse($this->filter->isApplicable($entity));
    }

    public static function notPersistedDataProvider(): array
    {
        return ['not an entity' => [false, false], 'new entity' => [true, true]];
    }

    public function testNotEligibleRecordSkipsTheAclCheck(): void
    {
        $entity = new CustomerStub();
        $this->expectPersisted($entity);
        $this->providerResolver->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($entity))
            ->willReturn(null);
        $this->authorizationChecker->expects(self::never())->method('isGranted');

        self::assertFalse($this->filter->isApplicable($entity));
    }

    /**
     * @dataProvider aclDataProvider
     */
    public function testEligibleRecordDependsOnView(bool $granted): void
    {
        $entity = new CustomerStub();
        $this->expectPersisted($entity);
        $provider = $this->createMock(SummaryFactProviderInterface::class);
        // The switch key is the provider's own; the filter never inspects it.
        $provider->expects(self::never())->method('getEnabledConfigKey');
        $provider->expects(self::never())->method('getFacts');
        $this->providerResolver->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($entity))
            ->willReturn($provider);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('VIEW', self::identicalTo($entity))
            ->willReturn($granted);

        self::assertSame($granted, $this->filter->isApplicable($entity));
    }

    public static function aclDataProvider(): array
    {
        return ['view denied' => [false], 'view granted' => [true]];
    }

    private function expectPersisted(object $entity): void
    {
        $this->doctrineHelper->expects(self::once())
            ->method('isManageableEntity')
            ->with(self::identicalTo($entity))
            ->willReturn(true);
        $this->doctrineHelper->expects(self::once())
            ->method('isNewEntity')
            ->with(self::identicalTo($entity))
            ->willReturn(false);
    }
}
