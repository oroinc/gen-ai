<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;

class LoadAiContentGenerationSettingsData extends AbstractFixture implements FixtureInterface
{
    private const array TRANSPORTS = [
        [
            'reference' => 'open_ai_transport',
            'type' => OpenAiChannel::TYPE,
            'label' => 'OpenAI',
            'token' => 'encrypted token',
        ],
        [
            'reference' => 'vertex_ai_transport',
            'type' => VertexAiChannel::TYPE,
            'label' => 'Vertex AI',
            'configFile' => ['config' => 'value'],
            'apiEndpoint' => 'https://test.com',
            'projectId' => 'vertex',
        ]
    ];

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        foreach (self::TRANSPORTS as $data) {
            if ($data['type'] === OpenAiChannel::TYPE) {
                $entity = new OpenAiTransportSettings();
                $entity->setToken($data['token']);
            } else {
                $entity = new VertexAiTransportSettings();
                $entity->setConfigFile(json_encode($data['configFile']));
                $entity->setApiEndpoint($data['apiEndpoint']);
                $entity->setProjectId($data['projectId']);
            }

            $entity->addLabel($this->createLocalizedValue($data['label']));
            $manager->persist($entity);
            $this->setReference($data['reference'], $entity);
        }
        $manager->flush();
    }

    private function createLocalizedValue(string $string): LocalizedFallbackValue
    {
        return (new LocalizedFallbackValue())->setString($string);
    }
}
