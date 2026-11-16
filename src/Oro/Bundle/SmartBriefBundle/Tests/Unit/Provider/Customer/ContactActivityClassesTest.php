<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider\Customer;

use Oro\Bundle\ActivityListBundle\Provider\ActivityListChainProvider;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EmailBundle\Entity\Email;
use Oro\Bundle\EmailBundle\Entity\EmailUser;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\ContactActivityClasses;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryAccessProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The default email, call and conversation channels, the channel list a subclass overrides, channel mapping,
 * missing classes ignored, activity-list classes only, and class-level VIEW on each activity's ACL class
 * (`EmailUser` for `Email`).
 */
final class ContactActivityClassesTest extends TestCase
{
    private const string MISSING_CLASS = 'Oro\Bundle\CallBundle\Entity\NotInstalledCall';

    private ActivityListChainProvider&MockObject $activityListProvider;
    private SummaryAccessProvider&MockObject $accessProvider;
    private FeatureChecker&MockObject $featureChecker;
    /** @var string[] activity classes whose feature is off */
    private array $disabledClasses = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->activityListProvider = $this->createMock(ActivityListChainProvider::class);
        $this->activityListProvider->expects(self::any())
            ->method('getSupportedActivities')
            ->willReturn([Email::class, Customer::class]);
        $this->activityListProvider->expects(self::any())
            ->method('getSupportedOwnerActivity')
            ->willReturnMap([[Email::class, EmailUser::class], [Customer::class, Customer::class]]);
        $this->accessProvider = $this->createMock(SummaryAccessProvider::class);
        $this->featureChecker = $this->createMock(FeatureChecker::class);
        $this->featureChecker->expects(self::any())
            ->method('isResourceEnabled')
            ->willReturnCallback(fn (string $class, string $type) => 'entities' === $type
                && !\in_array($class, $this->disabledClasses, true));
    }

    public function testDefaultChannels(): void
    {
        if (
            !class_exists(ContactActivityClasses::CALL_CLASS)
            || !class_exists(ContactActivityClasses::CONVERSATION_CLASS)
        ) {
            self::markTestSkipped('The call and conversation packages are not installed.');
        }
        $supported = [Email::class, ContactActivityClasses::CALL_CLASS, ContactActivityClasses::CONVERSATION_CLASS];
        $activityListProvider = $this->createMock(ActivityListChainProvider::class);
        $activityListProvider->expects(self::once())->method('getSupportedActivities')->willReturn($supported);
        $activityListProvider->expects(self::exactly(3))->method('getSupportedOwnerActivity')->willReturnArgument(0);
        $this->accessProvider->expects(self::exactly(3))->method('canView')->willReturn(true);

        self::assertSame(
            [
                Email::class => 'email',
                ContactActivityClasses::CALL_CLASS => 'call',
                ContactActivityClasses::CONVERSATION_CLASS => 'conversation',
            ],
            (new ContactActivityClasses($activityListProvider, $this->accessProvider, $this->featureChecker))
                ->getViewableClasses()
        );
    }

    public function testMapsViewableClassesToTheirChannel(): void
    {
        $this->accessProvider->expects(self::any())->method('canView')->willReturn(true);

        self::assertSame(
            [Email::class => 'email', Customer::class => 'visit'],
            $this->create(['email' => Email::class, 'visit' => Customer::class])->getViewableClasses()
        );
    }

    public function testClassesWhoseFeatureIsOffAreIgnored(): void
    {
        $this->disabledClasses = [Customer::class];
        $this->accessProvider->expects(self::any())->method('canView')->willReturn(true);

        self::assertSame(
            [Email::class => 'email'],
            $this->create(['email' => Email::class, 'visit' => Customer::class])->getViewableClasses()
        );
    }

    public function testMissingAndNonActivityClassesAreIgnoredWithoutError(): void
    {
        $this->accessProvider->expects(self::any())->method('canView')->willReturn(true);

        self::assertSame(
            [Email::class => 'email'],
            $this->create([
                'call' => self::MISSING_CLASS,
                'email' => Email::class,
                'portal' => CustomerUser::class,
            ])->getViewableClasses()
        );
    }

    /**
     * @dataProvider emailAccessDataProvider
     */
    public function testEmailAccessIsCheckedOnEmailUser(string $grantedClass, array $expected): void
    {
        $granted = [$grantedClass, Customer::class];
        $this->accessProvider->expects(self::exactly(2))
            ->method('canView')
            ->willReturnCallback(static fn (string $class) => \in_array($class, $granted, true));

        self::assertSame(
            $expected,
            $this->create(['email' => Email::class, 'visit' => Customer::class])->getViewableClasses()
        );
    }

    public static function emailAccessDataProvider(): array
    {
        return [
            'EmailUser granted' => [EmailUser::class, [Email::class => 'email', Customer::class => 'visit']],
            'only Email granted does not count' => [Email::class, [Customer::class => 'visit']],
        ];
    }

    private function create(array $classes): ContactActivityClasses
    {
        return new class (
            $classes,
            $this->activityListProvider,
            $this->accessProvider,
            $this->featureChecker
        ) extends ContactActivityClasses {
            public function __construct(
                private readonly array $classes,
                ActivityListChainProvider $activityListProvider,
                SummaryAccessProvider $accessProvider,
                FeatureChecker $featureChecker
            ) {
                parent::__construct($activityListProvider, $accessProvider, $featureChecker);
            }

            #[\Override]
            protected function getContactActivityClasses(): array
            {
                return $this->classes;
            }
        };
    }
}
