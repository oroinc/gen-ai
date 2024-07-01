<?php

namespace Oro\Bundle\AiContentGenerationBundle\Provider;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\LocaleBundle\Entity\Localization;

/**
 * Provides resolved Localization entity from the field path
 */
class LocalizationProvider
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function getLocalizationFromSubmittedField(
        string $formName,
        string $pluralPropertyName,
        string $formField
    ): ?Localization {
        $localization = null;
        if (str_contains($formField, sprintf('%s[%s][values][localizations]', $formName, $pluralPropertyName))) {
            $pattern = sprintf(
                '/%s\[%s\]\[values\]\[localizations\]\[(\d+)\]\[value\]/',
                $formName,
                $pluralPropertyName
            );

            if (!preg_match($pattern, $formField, $matches) || !isset($matches[1])) {
                throw new \Exception('This is not valid path for the changed field');
            }

            $localization = $this->getLocalisation($matches[1]);
        }

        return $localization;
    }

    private function getLocalisation(int $localisationId): ?Localization
    {
        return $this->registry
            ->getRepository(Localization::class)
            ->find($localisationId);
    }
}
