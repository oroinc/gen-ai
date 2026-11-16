<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\LocaleBundle\Entity\Localization;
use Oro\Bundle\LocaleBundle\Tests\Functional\DataFixtures\LoadLocalizationData;
use Oro\Bundle\TranslationBundle\Entity\Language;

/**
 * A German localization as an administrator creates it: the language is region-qualified (`de_DE`) and the
 * localization falls back to the default English one.
 */
class LoadGermanLocalizationData extends AbstractFixture implements DependentFixtureInterface
{
    public const string LOCALIZATION = 'smart_brief_de_DE_localization';
    public const string LANGUAGE_CODE = 'de_DE';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadLocalizationData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $language = $manager->getRepository(Language::class)->findOneBy(['code' => self::LANGUAGE_CODE]);
        if (null === $language) {
            $language = new Language();
            $language->setCode(self::LANGUAGE_CODE);
            $language->setEnabled(true);
            $manager->persist($language);
        }

        $localization = new Localization();
        $localization->setLanguage($language);
        $localization->setFormattingCode(self::LANGUAGE_CODE);
        $localization->setName('German (Germany)');
        $localization->setDefaultTitle('German (Germany)');
        $localization->setParentLocalization($this->getReference(LoadLocalizationData::DEFAULT_LOCALIZATION_CODE));
        $manager->persist($localization);
        $manager->flush();

        $this->addReference(self::LOCALIZATION, $localization);
    }
}
