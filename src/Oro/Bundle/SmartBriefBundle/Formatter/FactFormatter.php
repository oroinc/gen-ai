<?php

namespace Oro\Bundle\SmartBriefBundle\Formatter;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one place that turns facts into labelled, formatted lines, for the prompt and for the bullets.
 *
 * - Labels come from `oro_smart_brief.fact.{key}.label`.
 * - Values are delegated to the fact's value type; labels and values are in the current user's language and
 *   formatting locale, the defaults of Oro's translator and formatters.
 * - Every label and value is reduced to one line without `<`, `>` (or their fullwidth lookalikes) and without
 *   invisible format characters, so no fact can break the facts block or its delimiters whatever its source.
 *
 * A formatted fact: the label and value as they go to the prompt.
 *
 * @phpstan-type FormattedFact array{key: string, label: string, value: string}
 */
class FactFormatter
{
    public const string FACTS_OPEN = '<facts>';
    public const string FACTS_CLOSE = '</facts>';

    public function __construct(
        private readonly FactValueTypeRegistry $valueTypeRegistry,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * @return list<FormattedFact> in the FactSet's order
     */
    public function format(FactSet $facts, bool $forDisplay = false): array
    {
        $formatted = [];
        foreach ($facts as $fact) {
            $formatted[] = [
                'key' => $fact->key,
                'label' => $this->toSingleLine($this->getLabel($fact)),
                'value' => $this->toSingleLine($this->formatValue($fact, $forDisplay)),
            ];
        }

        return $formatted;
    }

    /**
     * The user content of the AI request: the facts between the delimiters, one `label: value` line each.
     */
    public function formatFactsBlock(FactSet $facts): string
    {
        $lines = array_map(
            static fn (array $fact) => sprintf('%s: %s', $fact['label'], $fact['value']),
            $this->format($facts)
        );

        $body = [] === $lines ? '' : implode("\n", $lines) . "\n";

        return self::FACTS_OPEN . "\n" . $body . self::FACTS_CLOSE;
    }

    private function getLabel(Fact $fact): string
    {
        return $this->translator->trans(sprintf('oro_smart_brief.fact.%s.label', $fact->key));
    }

    private function formatValue(Fact $fact, bool $forDisplay = false): string
    {
        return $this->valueTypeRegistry->get($fact->type)->format($fact, $forDisplay);
    }

    private function toSingleLine(string $text): string
    {
        $text = $this->replace('/\p{Cf}/u', '', $text);
        $text = $this->replace('/\s+/u', ' ', $text);

        return trim(strtr($text, ['<' => '‹', '>' => '›', '＜' => '‹', '＞' => '›']));
    }

    private function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text)
            ?? throw new \RuntimeException(sprintf('Fact formatting failed: %s.', preg_last_error_msg()));
    }
}
