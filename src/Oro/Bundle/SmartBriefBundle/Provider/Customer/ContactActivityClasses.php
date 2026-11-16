<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Customer;

use Oro\Bundle\ActivityListBundle\Provider\ActivityListChainProvider;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The activity classes that count as customer contact for the last contact facts and that the current user may see,
 * keyed by class with the channel name as the value. A class is left out when it does not exist (its bundle is not
 * installed), when its feature is off (such as Conversations), when it is not an activity-list activity, or when the
 * user has no class-level VIEW on its ACL class
 * (`EmailUser` for `Email`). The last check also matters for the query: the Activity list rule shows activities
 * without owners for every class, so classes the user cannot view must not be queried at all.
 *
 * To count more activity types as contact, extend this class and override {@see getContactActivityClasses()}.
 */
class ContactActivityClasses
{
    /** Activity classes of optional packages (oro/crm-call-bundle, oro/conversation), named without importing them */
    public const string CALL_CLASS = 'Oro\\Bundle\\CallBundle\\Entity\\Call';
    public const string CONVERSATION_CLASS = 'Oro\\Bundle\\ConversationBundle\\Entity\\Conversation';

    public function __construct(
        private readonly ActivityListChainProvider $activityListProvider,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly FeatureChecker $featureChecker
    ) {
    }

    /**
     * @return array<string, string> activity class => channel
     */
    public function getViewableClasses(): array
    {
        $supported = $this->activityListProvider->getSupportedActivities();
        $classes = [];
        foreach ($this->getContactActivityClasses() as $channel => $class) {
            if (
                class_exists($class)
                && $this->featureChecker->isResourceEnabled($class, 'entities')
                && \in_array($class, $supported, true)
                && $this->accessProvider->canView($this->activityListProvider->getSupportedOwnerActivity($class))
            ) {
                $classes[$class] = (string)$channel;
            }
        }

        return $classes;
    }

    /**
     * The activity classes that count as customer contact. A channel added here needs its label,
     * `oro_smart_brief.fact.last_contact_channel.choice.<channel>`.
     *
     * @return array<string, string> channel => activity class
     */
    protected function getContactActivityClasses(): array
    {
        return [
            'email' => Email::class,
            'call' => self::CALL_CLASS,
            'conversation' => self::CONVERSATION_CLASS,
        ];
    }
}
