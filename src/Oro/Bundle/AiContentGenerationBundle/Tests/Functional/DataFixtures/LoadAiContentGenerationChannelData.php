<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;

class LoadAiContentGenerationChannelData extends AbstractFixture implements DependentFixtureInterface
{
    private const array CHANNEL_DATA = [
        [
            'name' => 'Open AI',
            'type' => OpenAiChannel::TYPE,
            'enabled' => true,
            'transport' => 'open_ai_transport',
            'reference' => 'open_ai_channel',
        ],
        [
            'name' => 'Vertext AI',
            'type' => VertexAiChannel::TYPE,
            'enabled' => true,
            'transport' => 'vertex_ai_transport',
            'reference' => 'vertex_ai_channel',
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function getDependencies(): array
    {
        return [
            LoadAiContentGenerationSettingsData::class,
            LoadOrganization::class,
            LoadUser::class
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function load(ObjectManager $manager): void
    {
        foreach (self::CHANNEL_DATA as $data) {
            $entity = new Channel();
            $entity->setName($data['name']);
            $entity->setType($data['type']);
            $entity->setEnabled($data['enabled']);
            $entity->setDefaultUserOwner($this->getReference(LoadUser::USER));
            $entity->setOrganization($this->getReference(LoadOrganization::ORGANIZATION));
            $entity->setTransport($this->getReference($data['transport']));
            $this->setReference($data['reference'], $entity);
            $manager->persist($entity);
        }
        $manager->flush();
    }
}
