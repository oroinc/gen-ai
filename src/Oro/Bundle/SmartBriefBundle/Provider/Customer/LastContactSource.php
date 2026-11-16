<?php

namespace Oro\Bundle\SmartBriefBundle\Provider\Customer;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\ActivityListBundle\AccessRule\ActivityListAccessRule;
use Oro\Bundle\ActivityListBundle\Entity\ActivityList;
use Oro\Bundle\ActivityListBundle\Tools\ActivityListEntityConfigDumperExtension;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SecurityBundle\AccessRule\AclAccessRule;
use Oro\Bundle\SecurityBundle\Acl\BasicPermission;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\CalendarDateProvider;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;

/**
 * The last contact facts: the date (user timezone) and channel of the customer's most recent contact —
 * the activity-list record of a contact activity class ({@see ContactActivityClasses}) whose target is
 * the customer itself or one of its customer users (not child customers), newest first with the highest id
 * as the tie-break. It reads only the date and the class, never a subject or body; the channel is the
 * class's channel name, a choice the value type translates.
 *
 * The contact date is when the contact happened, not when the record was last edited: a call's `callDateTime`,
 * a conversation's last message time (its creation time while it has no message), and for any other class the
 * activity list's `updatedAt` (an email's sent date). A contact dated in the future (a planned call) is not a
 * contact yet and is skipped.
 *
 * One query per target type (the customer, its customer users), each joined from the target side so it is bounded
 * by this customer's own activities rather than by all activities of the installation; the newer of the two wins.
 * The query count is fixed. The ACL is the Activity list widget's own (`ActivityListIdProvider`): the
 * activity owners are joined as `ao` and checked per activity class. Only the activity classes the user
 * may view are queried, and customer users only when the user may view customer users.
 */
class LastContactSource
{
    private const string OWNER_ALIAS = 'ao';
    private const string TARGET_ALIAS = 'contactTarget';

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
        private readonly ContactActivityClasses $contactActivityClasses,
        private readonly SummaryAccessProvider $accessProvider,
        private readonly CalendarDateProvider $calendarDateProvider
    ) {
    }

    public function addFacts(FactSet $facts, Customer $customer): void
    {
        $classes = $this->contactActivityClasses->getViewableClasses();
        if (!$classes) {
            return;
        }
        $contact = $this->findLastContact(
            $customer,
            array_keys($classes),
            $this->accessProvider->canView(CustomerUser::class)
        );
        if (null === $contact || !isset($classes[$contact['activityClass']])) {
            return;
        }

        $facts->add(new Fact(
            CustomerSummaryFactProvider::LAST_CONTACT_DATE,
            FactValueType::DATE,
            $this->calendarDateProvider->toDate($contact['contactedAt'])
        ));
        $facts->add(new Fact(
            CustomerSummaryFactProvider::LAST_CONTACT_CHANNEL,
            FactValueType::CHOICE,
            $classes[$contact['activityClass']]
        ));
        // the email, call or conversation itself, as class and id
        $facts->setRecords(
            CustomerSummaryFactProvider::LAST_CONTACT_DATE,
            [$contact['activityClass'], (int)$contact['activityId']]
        );
    }

    /**
     * @param string[] $activityClasses
     *
     * @return array{contactedAt: \DateTimeInterface, activityClass: string, activityId: int}|null the contact time is
     *     in UTC
     */
    private function findLastContact(
        Customer $customer,
        array $activityClasses,
        bool $includeCustomerUsers
    ): ?array {
        $latest = $this->findLatest(Customer::class, $customer, $activityClasses);
        if ($includeCustomerUsers) {
            $latest = $this->newer($latest, $this->findLatest(CustomerUser::class, $customer, $activityClasses));
        }
        if (null === $latest) {
            return null;
        }

        return [
            'contactedAt' => $latest['contactedAt'],
            'activityClass' => $latest['activityClass'],
            'activityId' => $latest['activityId'],
        ];
    }

    /**
     * The latest contact with the customer itself, or with its customer users.
     *
     * @param string $targetClass Customer::class or CustomerUser::class
     *
     * @return array{contactedAt: \DateTimeImmutable, activityClass: string, activityId: int, id: int}|null the
     *     contact time in UTC
     */
    private function findLatest(string $targetClass, Customer $customer, array $activityClasses): ?array
    {
        $em = $this->doctrine->getManagerForClass(ActivityList::class);
        $association = ExtendHelper::buildAssociationName(
            $targetClass,
            ActivityListEntityConfigDumperExtension::ASSOCIATION_KIND
        );
        if (!$em->getClassMetadata(ActivityList::class)->hasAssociation($association)) {
            // the target type takes no activities in this application
            return null;
        }

        $qb = $em->createQueryBuilder()
            ->from(ActivityList::class, 'activity')
            ->innerJoin('activity.' . $association, self::TARGET_ALIAS)
            ->leftJoin('activity.activityOwners', self::OWNER_ALIAS)
            ->where(
                CustomerUser::class === $targetClass
                    ? self::TARGET_ALIAS . '.customer = :customer'
                    : self::TARGET_ALIAS . ' = :customer'
            )
            ->andWhere('activity.relatedActivityClass IN (:classes)')
            ->setParameter('customer', $customer)
            ->setParameter('classes', array_values($activityClasses));
        $contactedAt = $this->joinContactDate($qb, $activityClasses);
        $qb->select(
            $contactedAt . ' AS contactedAt',
            'activity.relatedActivityClass AS activityClass',
            'activity.relatedActivityId AS activityId',
            'activity.id AS id'
        )
            ->andWhere($contactedAt . ' <= :now')
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')), Types::DATETIME_MUTABLE)
            ->orderBy('contactedAt', 'DESC')
            ->addOrderBy('activity.id', 'DESC')
            ->setMaxResults(1);

        $rows = $this->aclHelper->apply($qb, BasicPermission::VIEW, [
            AclAccessRule::DISABLE_RULE => true,
            ActivityListAccessRule::ACTIVITY_OWNER_TABLE_ALIAS => self::OWNER_ALIAS,
        ])->getArrayResult();

        if (!isset($rows[0])) {
            return null;
        }
        $contact = $rows[0];
        // a COALESCE() comes back as the raw database value, a plain field as a DateTime; both are UTC
        $contact['contactedAt'] = $contact['contactedAt'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($contact['contactedAt'])->setTimezone(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable((string)$contact['contactedAt'], new \DateTimeZone('UTC'));

        return $contact;
    }

    /**
     * Joins the call and the conversation (when they are contact classes) and returns the contact date expression.
     *
     * @param string[] $activityClasses
     */
    private function joinContactDate(QueryBuilder $qb, array $activityClasses): string
    {
        $dates = [];
        if (\in_array(ContactActivityClasses::CALL_CLASS, $activityClasses, true)) {
            $qb->leftJoin(
                ContactActivityClasses::CALL_CLASS,
                'contactCall',
                Join::WITH,
                'activity.relatedActivityClass = :callClass AND contactCall.id = activity.relatedActivityId'
            )
                ->setParameter('callClass', ContactActivityClasses::CALL_CLASS);
            $dates[] = 'contactCall.callDateTime';
        }
        if (\in_array(ContactActivityClasses::CONVERSATION_CLASS, $activityClasses, true)) {
            $qb->leftJoin(
                ContactActivityClasses::CONVERSATION_CLASS,
                'contactConversation',
                Join::WITH,
                'activity.relatedActivityClass = :conversationClass'
                . ' AND contactConversation.id = activity.relatedActivityId'
            )
                ->leftJoin('contactConversation.lastMessage', 'contactMessage')
                ->setParameter('conversationClass', ContactActivityClasses::CONVERSATION_CLASS);
            $dates[] = 'contactMessage.createdAt';
            $dates[] = 'contactConversation.createdAt';
        }
        $dates[] = 'activity.updatedAt';

        return \count($dates) > 1 ? sprintf('COALESCE(%s)', implode(', ', $dates)) : $dates[0];
    }

    private function newer(?array $first, ?array $second): ?array
    {
        if (null === $first || null === $second) {
            return $first ?? $second;
        }
        $order = $first['contactedAt'] <=> $second['contactedAt'] ?: $first['id'] <=> $second['id'];

        return $order >= 0 ? $first : $second;
    }
}
