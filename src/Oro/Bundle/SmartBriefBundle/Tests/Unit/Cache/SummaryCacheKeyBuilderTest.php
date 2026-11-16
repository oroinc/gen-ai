<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Cache;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\LocaleBundle\Entity\Localization;
use Oro\Bundle\LocaleBundle\Manager\LocalizationManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCacheKeyBuilder;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Oro\Component\Testing\ReflectionUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Key composition in both refresh-on-change modes,
 * refused without an authenticated organization or user.
 */
final class SummaryCacheKeyBuilderTest extends TestCase
{
    private const string HASH = '9f2c0000000000000000000000000000000000000000000000000000000000ab';
    private const string CUSTOMER_CLASS = 'Oro\Bundle\CustomerBundle\Entity\Customer';

    private ConfigManager&MockObject $configManager;
    private TokenAccessorInterface&MockObject $tokenAccessor;
    private FactSet $facts;
    private SummaryCacheKeyBuilder $keyBuilder;
    /** The user's back-office localization; null when there is none */
    private ?Localization $localization = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->facts = new FactSet();
        $serializer = $this->createMock(FactSetSerializer::class);
        $serializer->expects(self::any())
            ->method('hash')
            ->with(self::identicalTo($this->facts))
            ->willReturn(self::HASH);

        $localizationManager = $this->createMock(LocalizationManager::class);
        $localizationManager->expects(self::any())
            ->method('getDefaultLocalization')
            ->willReturnCallback(fn () => $this->localization);

        $this->keyBuilder = new SummaryCacheKeyBuilder(
            $this->configManager,
            $this->tokenAccessor,
            $serializer,
            $localizationManager
        );
    }

    public function testKeyWithRefreshOnChangeEnabledContainsTheFactSetHash(): void
    {
        $this->expectConfig(true);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);
        $this->tokenAccessor->expects(self::never())->method('getUserId');
        $this->localization = $this->createLocalization(3);

        $key = $this->keyBuilder->build(
            'customer',
            self::CUSTOMER_CLASS,
            42,
            $this->facts
        );

        self::assertSame(
            'oro_smart_brief_'
            . hash('sha256', 'v1|backend|customer|' . self::CUSTOMER_CLASS . '|42|1|3|' . self::HASH),
            $key
        );
        self::assertTrue($this->keyBuilder->isRefreshOnChangeEnabled());
    }

    public function testFactsAreRequiredWhenRefreshOnChangeIsEnabled(): void
    {
        $this->expectConfig(true);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);

        $this->expectException(\LogicException::class);

        $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42);
    }

    public function testKeyWithRefreshOnChangeDisabledContainsTheUserAndLocalizationZero(): void
    {
        $this->expectConfig(false);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);
        $this->tokenAccessor->expects(self::once())->method('getUserId')->willReturn(17);

        self::assertFalse($this->keyBuilder->isRefreshOnChangeEnabled());
        $key = $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42);

        self::assertSame(
            'oro_smart_brief_' . hash('sha256', 'v1|backend|customer|' . self::CUSTOMER_CLASS . '|42|1|0|user:17'),
            $key
        );
    }

    public function testDifferentUsersGetDifferentKeysWhenRefreshOnChangeIsDisabled(): void
    {
        $this->expectConfig(false);
        $this->tokenAccessor->expects(self::any())->method('getOrganizationId')->willReturn(1);
        $this->tokenAccessor->expects(self::exactly(2))->method('getUserId')->willReturnOnConsecutiveCalls(17, 18);
        $this->localization = $this->createLocalization(1);

        self::assertNotSame(
            $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts),
            $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts)
        );
    }

    public function testDifferentLocalizationsGetDifferentKeys(): void
    {
        $this->expectConfig(true);
        $this->tokenAccessor->expects(self::any())->method('getOrganizationId')->willReturn(1);

        $this->localization = $this->createLocalization(3);
        $german = $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts);
        $this->localization = $this->createLocalization(5);

        self::assertNotSame($german, $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts));
    }

    public function testMissingOrganizationIsRefused(): void
    {
        $this->expectConfig(true);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->keyBuilder->build(
            'rfq',
            'Oro\Bundle\RFPBundle\Entity\Request',
            7,
            $this->facts
        );
    }

    public function testMissingUserIsRefusedWhenRefreshOnChangeIsOff(): void
    {
        $this->expectConfig(false);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);
        $this->tokenAccessor->expects(self::once())->method('getUserId')->willReturn(null);

        $this->expectException(\LogicException::class);

        $this->keyBuilder->build(
            'rfq',
            'Oro\Bundle\RFPBundle\Entity\Request',
            7,
            $this->facts
        );
    }

    public function testKeyFormatVersionIsPartOfTheKey(): void
    {
        self::assertSame('v1', SummaryCacheKeyBuilder::KEY_FORMAT_VERSION);
        self::assertSame('backend', SummaryCacheKeyBuilder::AUDIENCE_BACKEND);
    }

    private function createLocalization(int $id): Localization
    {
        $localization = new Localization();
        ReflectionUtil::setId($localization, $id);

        return $localization;
    }

    private function expectConfig(bool $refreshOnChange): void
    {
        $this->configManager->expects(self::any())
            ->method('get')
            ->with('oro_smart_brief.cache_invalidate_on_change')
            ->willReturn($refreshOnChange);
    }

    public function testStringIdentifierIsKeyedAsIs(): void
    {
        $this->expectConfig(false);
        $this->tokenAccessor->expects(self::once())->method('getOrganizationId')->willReturn(1);
        $this->tokenAccessor->expects(self::once())->method('getUserId')->willReturn(7);
        $uuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

        $key = $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, $uuid);

        self::assertSame(
            'oro_smart_brief_'
            . hash('sha256', 'v1|backend|customer|' . self::CUSTOMER_CLASS . '|' . $uuid . '|1|0|user:7'),
            $key
        );
    }

    public function testSameFactsOnAnotherDayGiveTheSameKey(): void
    {
        $this->expectConfig(true);
        $this->tokenAccessor->expects(self::exactly(2))->method('getOrganizationId')->willReturn(1);
        $this->localization = $this->createLocalization(3);

        $first = $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts);
        // The builder has no clock: only the facts, the record and the scope take part in the key.
        $second = $this->keyBuilder->build('customer', self::CUSTOMER_CLASS, 42, $this->facts);

        self::assertSame($first, $second);
    }
}
