<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Component\Testing\Unit\EntityTestCaseTrait;
use Oro\Component\Testing\Unit\EntityTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

final class OpenAiTransportSettingsTest extends TestCase
{
    use EntityTestCaseTrait;
    use EntityTrait;

    public function testAccessors(): void
    {
        self::assertPropertyAccessors(
            new OpenAiTransportSettings(),
            [
                ['token', 'some token'],
                ['model', 'some model'],
            ]
        );

        $openAiTransportSettings = new OpenAiTransportSettings();

        self::assertPropertyCollections(
            $openAiTransportSettings,
            [
                ['labels', new LocalizedFallbackValue()],
            ]
        );
    }

    public function testDefaultModel(): void
    {
        $openAiTransportSettings = new OpenAiTransportSettings();

        self::assertEquals(OpenAiTransportSettings::DEFAULT_MODEL, $openAiTransportSettings->getModel());
    }

    public function testGetSettingsBag(): void
    {
        $labels = new ArrayCollection([(new LocalizedFallbackValue())->setString('OpenAI')]);

        /** @var OpenAiTransportSettings $entity */
        $entity = $this->getEntity(
            OpenAiTransportSettings::class,
            [
                'token' => 'some token',
                'model' => 'some model',
                'labels' => $labels,
            ]
        );

        $result = $entity->getSettingsBag();

        self::assertInstanceOf(ParameterBag::class, $result);
        self::assertEquals('some token', $result->get(OpenAiTransportSettings::TOKEN));
        self::assertEquals('some model', $result->get(OpenAiTransportSettings::MODEL));
        self::assertEquals($labels, $result->get(OpenAiTransportSettings::LABELS));
    }
}
