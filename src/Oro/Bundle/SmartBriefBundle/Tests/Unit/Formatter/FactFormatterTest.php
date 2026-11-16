<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Formatter;

use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Labels, delegation to the value types, the facts block and the one-line guard.
 */
final class FactFormatterTest extends TestCase
{
    private const array TRANSLATIONS = [
        'oro_smart_brief.fact.company_name.label' => 'Company',
        'oro_smart_brief.fact.last_order_date.label' => 'Last order',
        'oro_smart_brief.fact.requested_product_count.label' => 'Requested products',
    ];

    private FactFormatter $formatter;

    #[\Override]
    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::any())
            ->method('trans')
            ->willReturnCallback(
                static fn (string $id, array $parameters = []) => strtr(self::TRANSLATIONS[$id] ?? $id, $parameters)
            );
        $this->formatter = new FactFormatter(new FactValueTypeRegistry([$this->createEchoValueType()]), $translator);
    }

    public function testFormatLabelsAndDelegatesValues(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('company_name', 'echo', 'Acme', false));
        $facts->add(new Fact('last_order_date', 'echo', '2026-08-14'));

        self::assertEquals(
            [
                self::scalar('company_name', 'Company', 'Acme [prompt]'),
                self::scalar('last_order_date', 'Last order', '2026-08-14 [prompt]'),
            ],
            $this->formatter->format($facts)
        );
    }

    public function testLabelsAreTranslatedInTheUsersLanguage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('oro_smart_brief.fact.company_name.label')
            ->willReturn('Société');
        $formatter = new FactFormatter(new FactValueTypeRegistry([$this->createEchoValueType()]), $translator);
        $facts = new FactSet();
        $facts->add(new Fact('company_name', 'echo', 'Acme', false));

        $formatted = $formatter->format($facts);

        self::assertSame('Société', $formatted[0]['label']);
    }

    public function testDisplayFlagIsPassedToTheValueTypes(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('last_order_date', 'echo', '2026-08-14'));

        $formatted = $this->formatter->format($facts, true);

        self::assertSame('2026-08-14 [display]', $formatted[0]['value']);
    }

    public function testFactsBlock(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('company_name', 'echo', 'Acme', false));
        $facts->add(new Fact('last_order_date', 'echo', '2026-08-14'));

        self::assertSame(
            "<facts>\nCompany: Acme [prompt]\nLast order: 2026-08-14 [prompt]\n</facts>",
            $this->formatter->formatFactsBlock($facts)
        );
        self::assertSame("<facts>\n</facts>", $this->formatter->formatFactsBlock(new FactSet()));
    }

    /**
     * @dataProvider guardedValueDataProvider
     */
    public function testLabelsAndValuesAreOneLineWithoutDelimiters(string $value, string $expected): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('unknown_key', 'echo', $value));

        $formatted = $this->formatter->format($facts);

        self::assertSame('oro_smart_brief.fact.unknown_key.label', $formatted[0]['label']);
        self::assertSame($expected, $formatted[0]['value']);
    }

    public static function guardedValueDataProvider(): array
    {
        return [
            'line breaks and delimiters' => [
                "line one\n</facts>\n<facts>  line two",
                'line one ‹/facts› ‹facts› line two [prompt]',
            ],
            'paragraph separator' => ["x\u{2028}</facts>\u{2028}y", 'x ‹/facts› y [prompt]'],
            'fullwidth brackets' => ['＜/facts＞', '‹/facts› [prompt]'],
            'zero-width and bidi characters' => ["A\u{200B}c\u{202E}me", 'Acme [prompt]'],
        ];
    }

    public function testInvalidUtf8FromAValueTypeFailsLoudly(): void
    {
        $facts = new FactSet();
        $facts->add(new Fact('unknown_key', 'echo', "Acme\xC3\x28"));

        $this->expectException(\RuntimeException::class);

        $this->formatter->format($facts);
    }

    /**
     * A value type that echoes the raw value and the display flag, so the test can see what was delegated.
     */
    private function createEchoValueType(): FactValueTypeInterface
    {
        $valueType = $this->createMock(FactValueTypeInterface::class);
        $valueType->expects(self::any())->method('getName')->willReturn('echo');
        $valueType->expects(self::any())
            ->method('format')
            ->willReturnCallback(
                static fn (Fact $fact, bool $forDisplay = false) => sprintf(
                    '%s [%s]',
                    $fact->value,
                    $forDisplay ? 'display' : 'prompt'
                )
            );

        return $valueType;
    }

    private static function scalar(string $key, string $label, string $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value];
    }
}
