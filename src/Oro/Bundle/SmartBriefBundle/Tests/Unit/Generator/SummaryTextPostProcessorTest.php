<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Generator;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryTextPostProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Covers the clean-up of the AI text.
 */
final class SummaryTextPostProcessorTest extends TestCase
{
    private SummaryTextPostProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->processor = new SummaryTextPostProcessor();
    }

    /**
     * @dataProvider textDataProvider
     */
    public function testProcess(string $text, string $expected): void
    {
        self::assertSame($expected, $this->processor->process($text));
    }

    public static function textDataProvider(): array
    {
        return [
            'plain' => ['Acme ordered twice.', 'Acme ordered twice.'],
            'surrounding whitespace and line breaks' => ["  Acme ordered\n twice.  \n", 'Acme ordered twice.'],
            'bullet markers removed, paired bold kept' => [
                "- **Acme** ordered twice\n- <b>x</b>",
                '**Acme** ordered twice <b>x</b>',
            ],
            'bold at the start of a line is not a list marker' => ['**2** open RFQs', '**2** open RFQs'],
            'unpaired bold markers are all removed' => ['Acme ordered **twice** for **$5', 'Acme ordered twice for $5'],
            'heading marker' => ['## Summary of Acme', 'Summary of Acme'],
            'numbered list' => ["1. First\n2) Second\n10. Tenth", 'First Second Tenth'],
            'nested markers' => ["  - * 3. Deep item", 'Deep item'],
            'underscore emphasis' => ['Acme __ordered__ twice', 'Acme ordered twice'],
            'inline asterisks are not list markers' => ['Acme ordered 2 * 3 items', 'Acme ordered 2 * 3 items'],
            'html is kept' => ['<script>x</script> ordered', '<script>x</script> ordered'],
            'multibyte whitespace' => ["Acme\u{00A0}ordered\ttwice", 'Acme ordered twice'],
            'bold at line start keeps its word' => ['**Acme** ordered twice', '**Acme** ordered twice'],
            'single-star italics are kept' => ['*Acme* ordered', '*Acme* ordered'],
            'bold italics are shown as bold' => ['***Acme*** ordered', '**Acme** ordered'],
            'leading sign is kept' => ['-5 degrees today', '-5 degrees today'],
            'leading decimal is kept' => ['1.5 million ordered', '1.5 million ordered'],
            'leading year is kept' => ['2024. Acme ordered', '2024. Acme ordered'],
            'marker without a space is kept' => ['-Acme ordered', '-Acme ordered'],
            'nested emphasis keeps the bold' => ['**bold __and__ under**', '**bold and under**'],
            'non-breaking space before a marker' => ["\u{00A0}- Acme", 'Acme'],
            'bold inside tag-like text stays text (escaped by the template)' => [
                '<scr**ipt**>x</scr**ipt**>',
                '<scr**ipt**>x</scr**ipt**>',
            ],
        ];
    }

    /**
     * @dataProvider emptyTextDataProvider
     */
    public function testEmptyResultIsAClientError(string $text): void
    {
        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('The AI returned an empty summary.');

        try {
            $this->processor->process($text);
        } catch (SummaryClientException $exception) {
            self::assertSame(SummaryClientException::REASON_CLIENT_ERROR, $exception->getReason());

            throw $exception;
        }
    }

    public static function emptyTextDataProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\t "],
            'markers only' => ["- \n* \n1. "],
            'empty emphasis' => ['****'],
        ];
    }

    public function testTextPcreCannotProcessIsAClientError(): void
    {
        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('The AI text could not be post-processed.');

        $this->processor->process("Acme \x80 ordered");
    }
}
