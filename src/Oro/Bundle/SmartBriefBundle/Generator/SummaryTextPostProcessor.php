<?php

namespace Oro\Bundle\SmartBriefBundle\Generator;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;

/**
 * Tidies the AI paragraph: paired `**` bold is kept for the block to show (the prompt asks for the key
 * values in bold; runs of three or more stars count as one `**`), but when the `**` markers do not pair up they are
 * all removed, so no stray asterisks are shown. `__` emphasis is unwrapped, leading list and heading markers (a
 * marker followed by whitespace, as in Markdown) are removed from every line, whitespace is collapsed and the result
 * is trimmed. HTML is kept (escaping handles it). Nothing usable left, or text PCRE cannot process (invalid
 * UTF-8, a pathological marker run), is a {@see SummaryClientException}: the facts are shown instead and nothing
 * half-processed is cached.
 */
class SummaryTextPostProcessor implements SummaryTextPostProcessorInterface
{
    private const string BOLD_MARKER = '**';
    private const string STAR_RUN_PATTERN = '/\*{3,}/u';
    private const string UNDERLINE_PATTERN = '/__(.+?)__/su';
    private const string LINE_MARKERS_PATTERN = '/^\s*(?:[-*#]+(?:\s+|$)|\d{1,3}[.)](?:\s+|$))+/mu';
    private const string WHITESPACE_PATTERN = '/\s+/u';

    #[\Override]
    public function process(string $text): string
    {
        // bold italics (`***x***`) are shown as bold
        $text = $this->replace(self::STAR_RUN_PATTERN, self::BOLD_MARKER, $text);
        if (0 !== substr_count($text, self::BOLD_MARKER) % 2) {
            $text = str_replace(self::BOLD_MARKER, '', $text);
        }
        $text = $this->replace(self::UNDERLINE_PATTERN, '$1', $text);
        $text = $this->replace(self::LINE_MARKERS_PATTERN, '', $text);
        $text = trim($this->replace(self::WHITESPACE_PATTERN, ' ', $text));

        if ('' === $text) {
            throw new SummaryClientException('The AI returned an empty summary.');
        }

        return $text;
    }

    private function replace(string $pattern, string $replacement, string $text): string
    {
        $result = preg_replace($pattern, $replacement, $text);
        if (null === $result) {
            throw new SummaryClientException('The AI text could not be post-processed.');
        }

        return $result;
    }
}
