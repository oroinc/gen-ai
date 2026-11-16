<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Resources;

use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The feature's configuration list, the built-in
 * providers' switches declared in this bundle's system configuration, and English text for every configuration
 * label and tooltip.
 */
final class SystemConfigurationResourcesTest extends TestCase
{
    private const string RESOURCES = __DIR__ . '/../../../Resources';

    public function testFeatureListsEveryFieldExceptTheSwitch(): void
    {
        $feature = $this->load('config/oro/features.yml')['features']['oro_smart_brief'];
        $fields = array_keys($this->load('config/oro/system_configuration.yml')['system_configuration']['fields']);

        self::assertSame('oro_smart_brief.enabled', $feature['toggle']);
        self::assertSame(array_values(array_diff($fields, ['oro_smart_brief.enabled'])), $feature['configuration']);
    }

    public function testBuiltInProviderSwitchesAreDeclared(): void
    {
        $fields = $this->load('config/oro/system_configuration.yml')['system_configuration']['fields'];
        foreach ([CustomerSummaryFactProvider::class, RfqSummaryFactProvider::class] as $class) {
            // the switch key needs none of the provider's collaborators
            $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertArrayHasKey($provider->getEnabledConfigKey(), $fields, $provider::class);
        }
    }

    public function testEveryConfigurationLabelAndTooltipHasEnglishText(): void
    {
        $config = $this->load('config/oro/system_configuration.yml')['system_configuration'];
        $keys = array_column($config['groups'], 'title');
        foreach ($config['fields'] as $field) {
            $keys[] = $field['options']['label'];
            $keys[] = $field['options']['tooltip'];
        }
        $messages = $this->flatten($this->load('translations/messages.en.yml'));

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $messages, $key);
            self::assertNotSame('', trim((string)$messages[$key]), $key);
        }
    }

    private function load(string $path): array
    {
        return Yaml::parseFile(self::RESOURCES . '/' . $path);
    }

    private function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string)$key : $prefix . '.' . $key;
            if (\is_array($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
