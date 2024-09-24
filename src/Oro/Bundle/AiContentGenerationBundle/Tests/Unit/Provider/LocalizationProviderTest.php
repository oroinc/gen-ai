<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Oro\Bundle\AiContentGenerationBundle\Provider\LocalizationProvider;
use Oro\Bundle\LocaleBundle\Entity\Localization;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LocalizationProviderTest extends TestCase
{
    private ManagerRegistry&MockObject $registry;

    private ObjectRepository&MockObject $repository;

    private LocalizationProvider $localizationProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->repository = $this->createMock(ObjectRepository::class);

        $this->registry
            ->expects(self::any())
            ->method('getRepository')
            ->with(Localization::class)
            ->willReturn($this->repository);

        $this->localizationProvider = new LocalizationProvider($this->registry);
    }

    public function testGetLocalizationFromSubmittedFieldWithValidPattern(): void
    {
        $formName = 'form';
        $pluralPropertyName = 'properties';
        $formField = 'form[properties][values][localizations][123][value]';

        $localization = $this->createMock(Localization::class);

        $this->repository
            ->method('find')
            ->with(123)
            ->willReturn($localization);

        $result = $this->localizationProvider->getLocalizationFromSubmittedField(
            $formName,
            $pluralPropertyName,
            $formField
        );

        self::assertEquals($localization, $result);
    }

    public function testGetLocalizationFromSubmittedFieldWithInvalidPattern(): void
    {
        self::expectExceptionObject(
            new \Exception('This is not valid path for the changed field')
        );

        $formName = 'form';
        $pluralPropertyName = 'properties';
        $formField = 'form[properties][values][localizations][abc][value]';

        $this->localizationProvider->getLocalizationFromSubmittedField($formName, $pluralPropertyName, $formField);
    }

    public function testGetLocalizationFromSubmittedFieldWithoutLocalizationId(): void
    {
        $formName = 'form';
        $pluralPropertyName = 'properties';
        $formField = 'form[properties][values][otherField][123][value]';

        $result = $this->localizationProvider->getLocalizationFromSubmittedField(
            $formName,
            $pluralPropertyName,
            $formField
        );

        self::assertNull($result);
    }

    public function testGetLocalizationWithNullId(): void
    {
        $this->repository
            ->method('find')
            ->with(9)
            ->willReturn(null);

        $result = $this->localizationProvider->getLocalizationFromSubmittedField(
            'form',
            'properties',
            'form[properties][values][localizations][9][value]'
        );

        self::assertNull($result);
    }
}
