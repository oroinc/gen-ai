<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Twig;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\KeyFactsProvider;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Oro\Bundle\SmartBriefBundle\Twig\SmartBriefExtension;
use Oro\Component\Testing\Unit\TwigExtensionTestCaseTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the Twig functions: the key facts rows and links from the provider, the facts fingerprint, the age of a
 * summary shown in the block header, and the AI text with bold values.
 */
final class SmartBriefExtensionTest extends TestCase
{
    use TwigExtensionTestCaseTrait;

    public function testRowsAndLinksComeFromTheKeyFactsProvider(): void
    {
        $view = new SummaryView(null, new FactSet(), false, new \DateTimeImmutable());
        $customer = new Customer();
        $rows = [['key' => 'open_rfq']];
        $link = ['url' => '/customer/5'];
        $provider = $this->createMock(KeyFactsProvider::class);
        $provider->expects(self::once())->method('getRows')->with(self::identicalTo($view))->willReturn($rows);
        $provider->expects(self::once())
            ->method('getLink')
            ->with(self::identicalTo($view), self::identicalTo($customer), 'open_rfq')
            ->willReturn($link);
        $extension = new SmartBriefExtension(
            self::getContainerBuilder()->add('oro_smart_brief.provider.key_facts', $provider)->getContainer($this)
        );

        self::assertSame($rows, self::callTwigFunction($extension, 'oro_smart_brief_facts', [$view]));
        self::assertSame(
            $link,
            self::callTwigFunction($extension, 'oro_smart_brief_link', [$view, $customer, 'open_rfq'])
        );
    }

    public function testFingerprintIsTheFactsHash(): void
    {
        $facts = new FactSet();
        $serializer = $this->createMock(FactSetSerializer::class);
        $serializer->expects(self::once())->method('hash')->with(self::identicalTo($facts))->willReturn('abc123');
        $container = self::getContainerBuilder()
            ->add(FactSetSerializer::class, $serializer)
            ->getContainer($this);

        self::assertSame('abc123', self::callTwigFunction(
            new SmartBriefExtension($container),
            'oro_smart_brief_fingerprint',
            [new SummaryView(null, $facts, false, new \DateTimeImmutable())]
        ));
    }

    /**
     * @dataProvider ageDataProvider
     */
    public function testAgeIsTheTimeSinceTheSummaryWasWritten(string $generatedAt, string $expected): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []) => trim(
                substr($id, \strlen('oro_smart_brief.block.updated.')) . ' ' . ($parameters['%count%'] ?? '')
            ));
        $container = self::getContainerBuilder()
            ->add(TranslatorInterface::class, $translator)
            ->getContainer($this);

        self::assertSame(
            $expected,
            self::callTwigFunction(
                new SmartBriefExtension($container),
                'oro_smart_brief_age',
                [new \DateTimeImmutable($generatedAt)]
            )
        );
    }

    public static function ageDataProvider(): array
    {
        return [
            'seconds' => ['-30 seconds', 'just_now'],
            'minutes' => ['-5 minutes', 'minutes_ago 5'],
            'hours' => ['-3 hours', 'hours_ago 3'],
            'days' => ['-2 days', 'days_ago 2'],
            'clock behind' => ['+5 minutes', 'just_now'],
        ];
    }

    /**
     * @dataProvider textDataProvider
     */
    public function testTextIsEscapedAndOnlyBoldBecomesMarkup(string $text, string $expected): void
    {
        $extension = new SmartBriefExtension(self::getContainerBuilder()->getContainer($this));

        self::assertSame($expected, self::callTwigFilter($extension, 'oro_smart_brief_text', [$text]));
    }

    public static function textDataProvider(): array
    {
        return [
            'bold values' => [
                'Last order **$16,000.00** on **Oct 25**.',
                'Last order <strong>$16,000.00</strong> on <strong>Oct 25</strong>.',
            ],
            'html is escaped, also inside bold' => [
                '<b>x</b> **<i>y</i>**',
                '&lt;b&gt;x&lt;/b&gt; <strong>&lt;i&gt;y&lt;/i&gt;</strong>',
            ],
            'no bold' => ['Plain & simple', 'Plain &amp; simple'],
        ];
    }
}
