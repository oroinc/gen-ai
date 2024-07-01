<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Component\Testing\Unit\EntityTestCaseTrait;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class VertexAiTransportSettingsTest extends TestCase
{
    use EntityTestCaseTrait;
    use EntityTrait;

    public function testAccessors(): void
    {
        self::assertPropertyAccessors(
            new VertexAiTransportSettings(),
            [
                ['configFile', 'config content'],
                ['apiEndpoint', '/prediction'],
                ['projectId', '12345'],
                ['location', 'some location'],
                ['model', 'some model'],
            ]
        );

        $vertexAiTransportSettings = new VertexAiTransportSettings();

        self::assertPropertyCollections(
            $vertexAiTransportSettings,
            [
                ['labels', new LocalizedFallbackValue()],
            ]
        );
    }

    public function testDefaultData(): void
    {
        $vertexAiTransportSettings = new VertexAiTransportSettings();

        self::assertEquals(VertexAiTransportSettings::DEFAULT_LOCATION, $vertexAiTransportSettings->getLocation());
        self::assertEquals(VertexAiTransportSettings::DEFAULT_MODEL, $vertexAiTransportSettings->getModel());
    }

    public function testGetSettingsBag(): void
    {
        $labels = new ArrayCollection([(new LocalizedFallbackValue())->setString('Vertex AI')]);

        /** @var VertexAiTransportSettings $entity */
        $entity = $this->getEntity(
            VertexAiTransportSettings::class,
            [
                'configFile' => 'config content',
                'apiEndpoint' => '/prediction',
                'projectId' => '12345',
                'location' => 'some location',
                'model' => 'some model',
                'labels' => $labels,
            ]
        );

        $result = $entity->getSettingsBag();

        self::assertInstanceOf(ParameterBag::class, $result);
        self::assertEquals('config content', $result->get(VertexAiTransportSettings::CONFIG_FILE));
        self::assertEquals('/prediction', $result->get(VertexAiTransportSettings::API_ENDPOINT));
        self::assertEquals('12345', $result->get(VertexAiTransportSettings::PROJECT_ID));
        self::assertEquals('some location', $result->get(VertexAiTransportSettings::LOCATION));
        self::assertEquals('some model', $result->get(VertexAiTransportSettings::MODEL));
        self::assertEquals($labels, $result->get(VertexAiTransportSettings::LABELS));
    }
}
