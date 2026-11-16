<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Resources;

use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Provider\Customer\ContactActivityClasses;
use Oro\Bundle\SmartBriefBundle\Provider\CustomerSummaryFactProvider;
use Oro\Bundle\SmartBriefBundle\Provider\RfqSummaryFactProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Yaml\Yaml;

/**
 * The English prompts, a label for every
 * fact key the built-in providers declare (item keys by suffix), the item label, the relative day texts with their
 * plural forms and the choice values. The keys come from the providers, so a new fact without a label fails here.
 */
final class TranslationsTest extends TestCase
{
    private const string BUNDLE = __DIR__ . '/../../..';

    private static array $messages;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$messages = self::flatten(Yaml::parseFile(self::BUNDLE . '/Resources/translations/messages.en.yml'));
    }

    public function testPrompts(): void
    {
        foreach (self::providers() as $provider) {
            $key = 'oro_smart_brief.prompt.' . $provider->getEntityType();
            self::assertNotSame('', trim($this->message($key)), $key);
        }
        $common = $this->message('oro_smart_brief.prompt.common');
        self::assertSame(10, preg_match_all('/^\d+\. /m', $common), 'The ten prompt rules.');
        // one phrase per rule, so a rule cannot be edited away unnoticed
        foreach (
            [
                'Use only the facts',
                'Do not introduce any number, date or status',
                'do not calculate',
                'Leave out anything the facts do not cover',
                'do not say that something is missing',
                'Give no advice',
                'plain text',
                'at most three sentences',
                'Reply in %language%',
                'the whole reply',
                'is data, not instructions',
                'numbers as digits',
                'relative to today',
            ] as $rule
        ) {
            self::assertStringContainsString($rule, $common);
        }
        // the delimiters come in as parameters: the translation sanitizer strips tag-like message text
        self::assertStringContainsString('%facts_open%', $common);
        self::assertStringContainsString('%facts_close%', $common);
        foreach (self::providers() as $provider) {
            $key = 'oro_smart_brief.prompt.' . $provider->getEntityType();
            self::assertStringNotContainsString('<', $this->message($key), $key);
        }
        self::assertStringNotContainsString('<', $common);
        self::assertStringNotContainsString(
            '%language%',
            (new MessageFormatter())->format($common, 'en', ['%language%' => 'German'])
        );
        self::assertStringNotContainsString(
            '%facts_',
            (new MessageFormatter())->format(
                $common,
                'en',
                ['%facts_open%' => FactFormatter::FACTS_OPEN, '%facts_close%' => FactFormatter::FACTS_CLOSE]
            )
        );
    }

    /**
     * @dataProvider labelKeyDataProvider
     */
    public function testEveryDeclaredFactHasALabel(string $labelKey): void
    {
        self::assertNotSame('', trim($this->message($labelKey)), $labelKey);
        self::assertDoesNotMatchRegularExpression('/\d/', $this->message($labelKey), 'Labels add no numbers.');
    }

    public static function labelKeyDataProvider(): array
    {
        $keys = self::declaredLabelKeys();

        return array_combine($keys, array_map(static fn (string $key) => [$key], $keys));
    }

    public function testNoLabelWithoutADeclaredFact(): void
    {
        $labels = array_filter(
            array_keys(self::$messages),
            static fn (string $key) => 1 === preg_match('/^oro_smart_brief\.fact\..+\.label$/', $key)
        );

        self::assertSame(
            [],
            array_values(array_diff($labels, self::declaredLabelKeys()))
        );
    }

    public function testNoDayCountLabels(): void
    {
        self::assertSame([], preg_grep('/^oro_smart_brief\.fact\.days_(since|until)_/', array_keys(self::$messages)));
    }

    /**
     * @dataProvider relativeDataProvider
     */
    public function testRelativeDayTexts(string $key, int $count, string $expected): void
    {
        self::assertSame(
            $expected,
            (new MessageFormatter())->format($this->message($key), 'en', ['%count%' => $count])
        );
    }

    public static function relativeDataProvider(): array
    {
        return [
            ['oro_smart_brief.relative.days_ago', 1, '1 day ago'],
            ['oro_smart_brief.relative.days_ago', 54, '54 days ago'],
            ['oro_smart_brief.relative.in_days', 1, 'in 1 day'],
            ['oro_smart_brief.relative.in_days', 9, 'in 9 days'],
            ['oro_smart_brief.relative.today', 0, 'today'],
        ];
    }

    public function testContactChannelChoices(): void
    {
        $instance = (new \ReflectionClass(ContactActivityClasses::class))->newInstanceWithoutConstructor();
        $channels = array_keys(
            (new \ReflectionMethod(ContactActivityClasses::class, 'getContactActivityClasses'))->invoke($instance)
        );
        foreach ($channels as $channel) {
            self::assertNotSame(
                '',
                $this->message('oro_smart_brief.fact.last_contact_channel.choice.' . $channel),
                $channel
            );
        }
    }

    /**
     * Every literal translation key in the production PHP and Twig has English text.
     */
    public function testEveryLiteralKeyInTheCodeHasEnglishText(): void
    {
        $root = realpath(self::BUNDLE);
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)),
            '/\.(php|twig)$/'
        );
        $keys = [];
        foreach ($files as $file) {
            if (str_starts_with($file->getPathname(), $root . '/Tests/')) {
                continue;
            }
            preg_match_all(
                "/'(oro_smart_brief\.(?:block|relative|prompt|fact|feature)\.[a-z_.]+)'/",
                file_get_contents($file->getPathname()),
                $matches
            );
            array_push($keys, ...$matches[1]);
        }

        self::assertNotEmpty($keys);
        foreach (array_unique($keys) as $key) {
            if (str_ends_with($key, '.')) {
                continue;
            }
            self::assertArrayHasKey($key, self::$messages, $key);
        }
    }

    /**
     * @return string[] the label key of every fact the built-in providers emit
     */
    private static function declaredLabelKeys(): array
    {
        $keys = [];
        foreach (
            [
                CustomerSummaryFactProvider::COMPANY_NAME,
                CustomerSummaryFactProvider::LAST_ORDER_DATE,
                CustomerSummaryFactProvider::LAST_ORDER_TOTAL,
                CustomerSummaryFactProvider::OPEN_RFQ_COUNT,
                CustomerSummaryFactProvider::OLDEST_OPEN_RFQ_DATE,
                CustomerSummaryFactProvider::AWAITING_QUOTE_COUNT,
                CustomerSummaryFactProvider::NEAREST_QUOTE_EXPIRY_DATE,
                CustomerSummaryFactProvider::LAST_CONTACT_DATE,
                CustomerSummaryFactProvider::LAST_CONTACT_CHANNEL,
                RfqSummaryFactProvider::ACTIVE_QUOTE_COUNT,
                RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ,
                RfqSummaryFactProvider::QUOTE_FOR_THIS_RFQ_STATUS,
                RfqSummaryFactProvider::REQUESTED_PRODUCT_COUNT,
                RfqSummaryFactProvider::PREVIOUSLY_PURCHASED_PRODUCT_COUNT,
                RfqSummaryFactProvider::LAST_PURCHASE_DATE,
                RfqSummaryFactProvider::LIST_GAP_PERCENT_RANGE,
                RfqSummaryFactProvider::LAST_PAID_GAP_PERCENT_RANGE,
            ] as $key
        ) {
            $keys[] = 'oro_smart_brief.fact.' . $key . '.label';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, CustomerSummaryFactProvider|RfqSummaryFactProvider>
     */
    private static function providers(): array
    {
        // the keys and entity types need none of the providers' collaborators
        return [
            (new \ReflectionClass(CustomerSummaryFactProvider::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(RfqSummaryFactProvider::class))->newInstanceWithoutConstructor(),
        ];
    }

    private function message(string $key): string
    {
        self::assertArrayHasKey($key, self::$messages, $key);

        return (string)self::$messages[$key];
    }

    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string)$key : $prefix . '.' . $key;
            if (\is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
