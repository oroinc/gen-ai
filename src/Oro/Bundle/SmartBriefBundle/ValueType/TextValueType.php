<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;

/**
 * Free text from a business record, sanitized so it can neither break the facts block nor carry
 * instructions to the model.
 *
 * - Invalid UTF-8 sequences are dropped first, so the regular expressions below cannot fail on them.
 * - Control characters (line breaks, tabs, …) become spaces and format characters (zero-width, bidi overrides)
 *   are removed, so the value is one line with no invisible characters.
 * - `<` and `>` become `‹` and `›`, so the fact delimiters cannot be forged.
 * - The result is cut to the configured maximum length.
 */
class TextValueType implements FactValueTypeInterface
{
    public function __construct(
        private readonly int $maxLength
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::TEXT;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return $this->sanitize((string)$fact->value);
    }

    #[\Override]
    public function canonicalize(mixed $value): string
    {
        return $this->sanitize((string)$value);
    }

    #[\Override]
    public function restore(string|int|array $canonical): string
    {
        return (string)$canonical;
    }

    private function sanitize(string $text): string
    {
        $text = $this->dropInvalidUtf8($text);
        $text = $this->replace('/\p{Cc}+/u', ' ', $text);
        $text = $this->replace('/\p{Cf}/u', '', $text);
        $text = strtr($text, ['<' => '‹', '>' => '›']);
        $text = trim($this->replace('/\s+/u', ' ', $text));

        return rtrim(mb_substr($text, 0, $this->maxLength));
    }

    private function dropInvalidUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $substituteCharacter = mb_substitute_character();
        mb_substitute_character('none');
        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($substituteCharacter);
        }
    }

    private function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text)
            ?? throw new \RuntimeException(sprintf('Text sanitization failed: %s.', preg_last_error_msg()));
    }
}
