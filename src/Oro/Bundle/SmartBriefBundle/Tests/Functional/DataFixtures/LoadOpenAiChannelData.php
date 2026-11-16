<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;

/**
 * An enabled OpenAI integration channel with dummy transport settings for the summary feature's functional tests.
 */
class LoadOpenAiChannelData extends AbstractFixture implements DependentFixtureInterface
{
    public const string CHANNEL = 'oro_smart_brief.channel.open_ai';
    public const string DISABLED_CHANNEL = 'oro_smart_brief.channel.open_ai_disabled';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOrganization::class, LoadUser::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        foreach ([self::CHANNEL => true, self::DISABLED_CHANNEL => false] as $reference => $enabled) {
            $transport = new OpenAiTransportSettings();
            $transport->setToken('stub-token');
            $transport->setModel('gpt-5-mini');
            $transport->addLabel((new LocalizedFallbackValue())->setString('OpenAI summaries'));
            $manager->persist($transport);

            $channel = new Channel();
            $channel->setName($enabled ? 'OpenAI summaries' : 'OpenAI summaries (disabled)');
            $channel->setType(OpenAiChannel::TYPE);
            $channel->setEnabled($enabled);
            $channel->setDefaultUserOwner($this->getReference(LoadUser::USER));
            $channel->setOrganization($this->getReference(LoadOrganization::ORGANIZATION));
            $channel->setTransport($transport);
            $manager->persist($channel);
            $this->setReference($reference, $channel);
        }
        $manager->flush();
    }
}
