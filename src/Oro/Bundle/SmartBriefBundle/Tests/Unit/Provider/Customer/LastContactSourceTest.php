<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Customer;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\ContactActivityClasses;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\LastContactSource;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\FactSourceTestTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the last contact facts: the date in the user's calendar and the
 * channel key, the newer of the customer's and its customer users' contacts, customer users searched only with VIEW
 * on them, nothing queried without a viewable contact class, a contact of an unmapped class dropped. The queries
 * themselves are covered by the functional tests.
 */
final class LastContactSourceTest extends TestCase
{
    use FactSourceTestTrait;

    private const string CALL = 'Oro\Bundle\CallBundle\Entity\Call';

    private AclHelper&MockObject $aclHelper;
    private ContactActivityClasses&MockObject $contactActivityClasses;

    #[\Override]
    protected function setUp(): void
    {
        $this->aclHelper = $this->createMock(AclHelper::class);
        $this->contactActivityClasses = $this->createMock(ContactActivityClasses::class);
    }

    /**
     * @dataProvider timezoneDataProvider
     */
    public function testDateInTheUserCalendarAndTheChannel(
        string $timezone,
        string $contactedAtUtc,
        string $expectedDate
    ): void {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([Email::class => 'email', self::CALL => 'call']);
        $this->expectRows([$this->row($contactedAtUtc, Email::class, 1)]);

        $facts = $this->getFactSet($timezone);

        self::assertEquals(
            [
                new Fact('last_contact_date', FactValueType::DATE, $expectedDate),
                new Fact('last_contact_channel', FactValueType::CHOICE, 'email'),
            ],
            array_values(iterator_to_array($facts)),
            'Date and channel only, no days-since fact.'
        );
        self::assertSame([Email::class, 501], $facts->getRecords('last_contact_date'), 'The record to link to.');
    }

    public static function timezoneDataProvider(): array
    {
        return [
            'Berlin, morning UTC' => ['Europe/Berlin', '2026-10-04 09:00:00', '2026-10-04'],
            'Berlin, late UTC crosses to the next day' => ['Europe/Berlin', '2026-10-04 22:30:00', '2026-10-05'],
            'Los Angeles, early UTC' => ['America/Los_Angeles', '2026-10-04 03:00:00', '2026-10-03'],
        ];
    }

    public function testTheNewerOfTheCustomersAndItsUsersContactsWins(): void
    {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([Email::class => 'email', self::CALL => 'call']);
        $this->expectRows(
            [$this->row('2026-10-01 10:00:00', Email::class, 5)],
            [$this->row('2026-10-03 10:00:00', self::CALL, 2)]
        );

        self::assertEquals(
            [
                new Fact('last_contact_date', FactValueType::DATE, '2026-10-03'),
                new Fact('last_contact_channel', FactValueType::CHOICE, 'call'),
            ],
            $this->getFacts('UTC', CustomerUser::class)
        );
    }

    /**
     * @dataProvider customerUserAccessDataProvider
     */
    public function testCustomerUsersAreSearchedOnlyWithViewOnCustomerUser(array $viewable, int $expectedQueries): void
    {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([Email::class => 'email']);
        $this->aclHelper->expects(self::exactly($expectedQueries))
            ->method('apply')
            ->willReturn($this->createQuery('getArrayResult', []));

        self::assertSame([], $this->getFacts('UTC', ...$viewable));
    }

    public static function customerUserAccessDataProvider(): array
    {
        return ['granted' => [[CustomerUser::class], 2], 'denied' => [[], 1]];
    }

    public function testNoViewableContactClassMeansNoQuery(): void
    {
        $this->contactActivityClasses->expects(self::once())->method('getViewableClasses')->willReturn([]);
        $this->aclHelper->expects(self::never())->method('apply');

        self::assertSame([], $this->getFacts());
    }

    public function testAContactOfAnUnmappedClassIsDropped(): void
    {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([Email::class => 'email']);
        $this->expectRows([$this->row('2026-10-04 09:00:00', 'Acme\Visit', 1)]);

        self::assertSame([], $this->getFacts());
    }

    public function testNoContactMeansNoFacts(): void
    {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([Email::class => 'email']);
        $this->expectRows([]);

        self::assertSame([], $this->getFacts());
    }

    /**
     * @param array ...$results the rows of the customer query, then of the customer user query
     */
    private function expectRows(array ...$results): void
    {
        $this->aclHelper->expects(self::exactly(\count($results)))
            ->method('apply')
            ->willReturnOnConsecutiveCalls(
                ...array_map(fn (array $rows) => $this->createQuery('getArrayResult', $rows), $results)
            );
    }

    /**
     * A call or conversation date comes from a COALESCE(), which Doctrine returns as the raw database value.
     */
    public function testRawDatabaseDateIsReadAsUtc(): void
    {
        $this->contactActivityClasses->expects(self::once())
            ->method('getViewableClasses')
            ->willReturn([self::CALL => 'call']);
        $this->expectRows([[
            'contactedAt' => '2026-10-04 22:30:00',
            'activityClass' => self::CALL,
            'activityId' => 501,
            'id' => 1,
        ]], []);

        self::assertEquals(
            [
                new Fact('last_contact_date', FactValueType::DATE, '2026-10-05'),
                new Fact('last_contact_channel', FactValueType::CHOICE, 'call'),
            ],
            $this->getFacts('Europe/Berlin', CustomerUser::class)
        );
    }

    private function row(string $contactedAtUtc, string $activityClass, int $id): array
    {
        return [
            'contactedAt' => new \DateTime($contactedAtUtc, new \DateTimeZone('UTC')),
            'activityClass' => $activityClass,
            'activityId' => 500 + $id,
            'id' => $id,
        ];
    }

    /**
     * @return Fact[]
     */
    private function getFacts(string $timezone = 'UTC', string ...$viewable): array
    {
        return array_values(iterator_to_array($this->getFactSet($timezone, ...$viewable)));
    }

    private function getFactSet(string $timezone = 'UTC', string ...$viewable): FactSet
    {
        $source = new LastContactSource(
            $this->createDoctrineWithActivityAssociations(),
            $this->aclHelper,
            $this->contactActivityClasses,
            $this->createAccessProvider(...$viewable),
            $this->createCalendarDateProvider($timezone)
        );

        return $this->collectFactSet(static fn ($facts) => $source->addFacts($facts, new Customer()));
    }

    private function createDoctrineWithActivityAssociations(): ManagerRegistry
    {
        /** @var EntityManagerInterface&MockObject $em */
        $doctrine = $this->createDoctrine($em);
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::any())->method('hasAssociation')->willReturn(true);
        $em->expects(self::any())->method('getClassMetadata')->willReturn($metadata);

        return $doctrine;
    }
}
