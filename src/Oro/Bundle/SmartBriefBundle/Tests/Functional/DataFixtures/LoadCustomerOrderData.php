<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ObjectManager;
use Oro\Bundle\CurrencyBundle\Entity\MultiCurrency;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOption;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Provider\OrderStatusesProviderInterface;
use Oro\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadOrderUsers;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadOrganization;
use Oro\Bundle\TestFrameworkBundle\Tests\Functional\DataFixtures\LoadUser;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * Orders for the last order tests. CUSTOMER has, newest first: an order of its child customer (10-02, ignored), a
 * sub-order (10-01, ignored), a cancelled order (09-30, ignored), an order without status owned by another user
 * (09-10, the expected last order; its status is removed in the database after the flush because the order status
 * listener fills in the default status on persist) and an open order owned by the test user (08-14, the last
 * order a user with user-level access sees). TIE_CUSTOMER has two orders with the same creation time; the one
 * created second (higher id) is the last order.
 */
class LoadCustomerOrderData extends AbstractFixture implements DependentFixtureInterface
{
    public const string CUSTOMER = 'smart_brief.customer.orders';
    public const string CHILD_CUSTOMER = 'smart_brief.customer.orders.child';
    public const string CUSTOMER_WITHOUT_ORDERS = 'smart_brief.customer.no_orders';
    public const string CUSTOMER_CANCELLED_ONLY = 'smart_brief.customer.cancelled_only';
    public const string PERFORMANCE_CUSTOMER = 'smart_brief.customer.performance';
    public const string OPEN_ORDER = 'smart_brief.order.open';
    public const string NO_STATUS_ORDER = 'smart_brief.order.no_status';
    public const string PERFORMANCE_ORDER = 'smart_brief.order.performance';
    public const string TIE_CUSTOMER = 'smart_brief.customer.tie';
    public const string TIE_SECOND_ORDER = 'smart_brief.order.tie.second';

    #[\Override]
    public function getDependencies(): array
    {
        return [LoadOrganization::class, LoadUser::class, LoadOrderUsers::class];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        /** @var Organization $organization */
        $organization = $this->getReference(LoadOrganization::ORGANIZATION);
        /** @var User $user */
        $user = $this->getReference(LoadUser::USER);
        /** @var User $otherUser */
        $otherUser = $this->getReference(LoadOrderUsers::ORDER_USER_1);

        $customer = $this->createCustomer($manager, self::CUSTOMER, $organization, $user);
        $child = $this->createCustomer($manager, self::CHILD_CUSTOMER, $organization, $user);
        $child->setParent($customer);
        $this->createCustomer($manager, self::CUSTOMER_WITHOUT_ORDERS, $organization, $user);
        $cancelledOnly = $this->createCustomer($manager, self::CUSTOMER_CANCELLED_ONLY, $organization, $user);
        $performance = $this->createCustomer($manager, self::PERFORMANCE_CUSTOMER, $organization, $user);
        $tie = $this->createCustomer($manager, self::TIE_CUSTOMER, $organization, $user);

        $cancelledStatus = OrderStatusesProviderInterface::INTERNAL_STATUS_CANCELLED;
        $open = $this->createOrder($manager, self::OPEN_ORDER, $customer, $user, '2026-08-14 15:00:00', '12480.0000');
        $open->setInternalStatus(
            $this->getInternalStatus($manager, OrderStatusesProviderInterface::INTERNAL_STATUS_OPEN)
        );
        $noStatus = $this->createOrder(
            $manager,
            self::NO_STATUS_ORDER,
            $customer,
            $otherUser,
            '2026-09-10 10:00:00',
            '500.0000',
            'EUR'
        );
        $this->createOrder($manager, 'smart_brief.order.cancelled', $customer, $user, '2026-09-30 10:00:00', '1.0000')
            ->setInternalStatus($this->getInternalStatus($manager, $cancelledStatus));
        $this->createOrder($manager, 'smart_brief.order.sub', $customer, $user, '2026-10-01 10:00:00', '2.0000')
            ->setParent($noStatus);
        $this->createOrder($manager, 'smart_brief.order.child', $child, $user, '2026-10-02 10:00:00', '3.0000');
        $this->createOrder(
            $manager,
            'smart_brief.order.only_cancelled',
            $cancelledOnly,
            $user,
            '2026-09-01 10:00:00',
            '4.0000'
        )->setInternalStatus($this->getInternalStatus($manager, $cancelledStatus));
        $this->createOrder($manager, self::PERFORMANCE_ORDER, $performance, $user, '2026-01-01 10:00:00', '5.0000');
        $this->createOrder($manager, 'smart_brief.order.tie.first', $tie, $user, '2020-01-01 10:00:00', '6.0000');
        $manager->flush();
        // created second, so it has the higher id
        $this->createOrder($manager, self::TIE_SECOND_ORDER, $tie, $user, '2020-01-01 10:00:00', '7.0000');

        $manager->flush();
        $manager->getConnection()->executeStatement(
            "UPDATE oro_order SET serialized_data = serialized_data - 'internal_status' WHERE id = :id",
            ['id' => $noStatus->getId()],
            ['id' => ParameterType::INTEGER]
        );
    }

    private function createCustomer(
        ObjectManager $manager,
        string $name,
        Organization $organization,
        User $owner
    ): Customer {
        $customer = (new Customer())
            ->setName($name)
            ->setOrganization($organization)
            ->setOwner($owner);
        $manager->persist($customer);
        $this->setReference($name, $customer);

        return $customer;
    }

    private function createOrder(
        ObjectManager $manager,
        string $reference,
        Customer $customer,
        User $owner,
        string $createdAt,
        string $total,
        string $currency = 'USD'
    ): Order {
        $order = new Order();
        $order->setIdentifier($reference);
        $order->setOwner($owner);
        $order->setOrganization($customer->getOrganization());
        $order->setCustomer($customer);
        $order->setCurrency($currency);
        $order->setTotalObject(MultiCurrency::create($total, $currency, $total));
        $order->setCreatedAt(new \DateTime($createdAt, new \DateTimeZone('UTC')));
        $manager->persist($order);
        $this->setReference($reference, $order);

        return $order;
    }

    private function getInternalStatus(ObjectManager $manager, string $status): EnumOption
    {
        return $manager->getRepository(EnumOption::class)
            ->find(ExtendHelper::buildEnumOptionId(Order::INTERNAL_STATUS_CODE, $status));
    }
}
