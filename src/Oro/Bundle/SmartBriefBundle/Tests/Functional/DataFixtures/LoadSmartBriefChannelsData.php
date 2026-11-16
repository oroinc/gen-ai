<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;

/**
 * Next to the OpenAI channels of {@see LoadOpenAiChannelData}: a Vertex AI channel and a channel of another type,
 * which the SmartBrief integration selector must not offer.
 */
class LoadSmartBriefChannelsData extends AbstractFixture implements DependentFixtureInterface
{
    public const string VERTEX_CHANNEL = 'oro_smart_brief.channel.vertex_ai';
    public const string OTHER_CHANNEL = 'oro_smart_brief.channel.other';
    /** an unregistered type: proves the type filter without depending on another integration bundle */
    public const string OTHER_TYPE = 'smart_brief_test_other';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOpenAiChannelData::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $transport = (new VertexAiTransportSettings())
            ->setProjectId('stub-project')
            ->setLocation('us-central1')
            ->setModel('gemini-2.5-flash');
        $transport->addLabel((new LocalizedFallbackValue())->setString('Vertex AI summaries'));
        $manager->persist($transport);
        $this->createChannel($manager, self::VERTEX_CHANNEL, 'Vertex AI summaries', 'vertex_ai')
            ->setTransport($transport);
        $this->createChannel($manager, self::OTHER_CHANNEL, 'Another integration', self::OTHER_TYPE);
        $manager->flush();
    }

    private function createChannel(ObjectManager $manager, string $reference, string $name, string $type): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setType($type);
        $channel->setEnabled(true);
        $channel->setDefaultUserOwner($this->getReference(LoadUser::USER));
        $channel->setOrganization($this->getReference(LoadOrganization::ORGANIZATION));
        $manager->persist($channel);
        $this->setReference($reference, $channel);

        return $channel;
    }
}
