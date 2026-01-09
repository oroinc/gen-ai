<?php

namespace Oro\Bundle\AiContentGenerationBundle\Provider;

use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\Id\ConfigIdInterface;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Extend\FieldTypeHelper;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;

/**
 * Provides attributes for entity class
 */
class ExtendConfigsProvider
{
    public function __construct(
        private readonly ConfigProvider $extendConfigProvider,
        private readonly ConfigProvider $viewConfigProvider,
        private readonly FieldTypeHelper $fieldTypeHelper,
        private readonly FeatureChecker $featureChecker,
    ) {
    }

    public function getAttributes(string $entityClassName): iterable
    {
        $extendConfigs = $this->extendConfigProvider->getConfigs($entityClassName);

        foreach ($extendConfigs as $extendConfig) {
            if (!$this->isValid($extendConfig)) {
                continue;
            }

            yield $extendConfig;
        }
    }

    private function isValid(ConfigInterface $extendConfig): bool
    {
        if ($this->isSystemField($extendConfig) || !$this->isAccessibleField($extendConfig)) {
            return false;
        }

        $fieldConfigId = $extendConfig->getId();

        if ($this->isInvisibleField($fieldConfigId) || !$extendConfig->has('target_entity')) {
            return false;
        }

        $targetEntity = $extendConfig->get('target_entity');

        if (
            $this->isEntityDisabledByFeature($targetEntity) ||
            $this->hasRelationsReferencedToNotAccessibleEntity($fieldConfigId) ||
            !$this->isEntityAccessible($targetEntity)
        ) {
            return false;
        }

        return true;
    }

    private function isSystemField(ConfigInterface $extendConfig): bool
    {
        return !$extendConfig->is('owner', ExtendScope::OWNER_CUSTOM);
    }

    private function isAccessibleField(ConfigInterface $extendConfig): bool
    {
        return ExtendHelper::isFieldAccessible($extendConfig);
    }

    private function isInvisibleField(ConfigIdInterface $fieldConfigId): bool
    {
        return !$this->viewConfigProvider->getConfigById($fieldConfigId)->is('is_displayable');
    }

    private function isEntityDisabledByFeature(string $targetEntity): bool
    {
        return !$this->featureChecker->isResourceEnabled($targetEntity, 'entities');
    }

    private function hasRelationsReferencedToNotAccessibleEntity(ConfigIdInterface $fieldConfigId): bool
    {
        $underlyingFieldType = $this->fieldTypeHelper->getUnderlyingType($fieldConfigId->getFieldType());

        return in_array($underlyingFieldType, RelationType::$anyToAnyRelations, true);
    }

    private function isEntityAccessible(string $targetEntity): bool
    {
        return ExtendHelper::isEntityAccessible($this->extendConfigProvider->getConfig($targetEntity));
    }
}
