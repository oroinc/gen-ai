<?php

namespace Oro\Bundle\SmartBriefBundle\EventListener\Datagrid;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Event\BuildBefore;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\Feature\Voter\FeatureVoter;
use Oro\Bundle\SmartBriefBundle\Provider\KeyFactsProvider;

/**
 * Adds the SmartBrief grid builder to the grids the block links to ({@see KeyFactsProvider::getLinkedGrids()}), so a
 * link can filter such a grid in place. Nothing is added while the feature is off.
 */
class SmartBriefFilterBuilderListener
{
    public const string JS_MODULE = 'orosmartbrief/js/datagrid/smart-brief-filter-builder';

    public function __construct(
        private readonly FeatureChecker $featureChecker,
        private readonly KeyFactsProvider $keyFactsProvider
    ) {
    }

    public function onBuildBefore(BuildBefore $event): void
    {
        $config = $event->getConfig();
        if (
            !\in_array($config->getName(), $this->keyFactsProvider->getLinkedGrids(), true)
            || !$this->featureChecker->isFeatureEnabled(FeatureVoter::FEATURE_NAME)
        ) {
            return;
        }

        $modules = (array)$config->offsetGetByPath(DatagridConfiguration::JS_MODULES_PATH, []);
        if (!\in_array(self::JS_MODULE, $modules, true)) {
            $modules[] = self::JS_MODULE;
            $config->offsetSetByPath(DatagridConfiguration::JS_MODULES_PATH, $modules);
        }
    }
}
