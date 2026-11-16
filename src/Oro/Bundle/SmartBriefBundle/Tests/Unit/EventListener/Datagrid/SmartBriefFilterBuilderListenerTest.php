<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\EventListener\Datagrid;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datagrid\DatagridInterface;
use Oro\Bundle\DataGridBundle\Event\BuildBefore;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\EventListener\Datagrid\SmartBriefFilterBuilderListener;
use Oro\Bundle\SmartBriefBundle\Provider\KeyFactsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers adding the SmartBrief grid builder: to the linked grids only, once, next to the grid's own modules, and only
 * while the feature is on.
 */
final class SmartBriefFilterBuilderListenerTest extends TestCase
{
    /**
     * @dataProvider modulesDataProvider
     */
    public function testBuilderIsAddedOnce(array $modules, array $expected): void
    {
        $config = DatagridConfiguration::create(['name' => 'acme-grid', 'options' => ['jsmodules' => $modules]]);

        $this->createListener(true)->onBuildBefore($this->createEvent($config));

        self::assertSame($expected, $config->offsetGetByPath('[options][jsmodules]'));
    }

    public static function modulesDataProvider(): array
    {
        $module = SmartBriefFilterBuilderListener::JS_MODULE;

        return [
            'no modules' => [[], [$module]],
            'next to the grid\'s own modules' => [['acme/js/builder'], ['acme/js/builder', $module]],
            'already added' => [[$module], [$module]],
        ];
    }

    public function testNothingIsAddedWhileTheFeatureIsOff(): void
    {
        $config = DatagridConfiguration::create(['name' => 'acme-grid']);

        $this->createListener(false)->onBuildBefore($this->createEvent($config));

        self::assertNull($config->offsetGetByPath('[options][jsmodules]'));
    }

    public function testOtherGridsAreLeftAlone(): void
    {
        $config = DatagridConfiguration::create(['name' => 'another-grid']);

        $this->createListener(null)->onBuildBefore($this->createEvent($config));

        self::assertNull($config->offsetGetByPath('[options][jsmodules]'));
    }

    /**
     * @param bool|null $featureEnabled null when the feature must not be checked
     */
    private function createListener(?bool $featureEnabled): SmartBriefFilterBuilderListener
    {
        $featureChecker = $this->createMock(FeatureChecker::class);
        $featureChecker->expects(null === $featureEnabled ? self::never() : self::once())
            ->method('isFeatureEnabled')
            ->with('oro_smart_brief')
            ->willReturn((bool)$featureEnabled);
        $keyFactsProvider = $this->createMock(KeyFactsProvider::class);
        $keyFactsProvider->expects(self::any())->method('getLinkedGrids')->willReturn(['acme-grid']);

        return new SmartBriefFilterBuilderListener($featureChecker, $keyFactsProvider);
    }

    private function createEvent(DatagridConfiguration $config): BuildBefore
    {
        return new BuildBefore($this->createMock(DatagridInterface::class), $config);
    }
}
