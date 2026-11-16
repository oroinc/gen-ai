<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A percentage difference kept as a decimal string (`8.3` for 8.3 %), or a range of them from the lowest to the
 * highest (`-13.0/-8.0`), shown by Oro's percent formatter with one fraction digit and an explicit sign (`+8.3%`,
 * `-2.0%`), so the unit travels with the value. A range joins its two formatted ends with the
 * `oro_smart_brief.range` translation.
 */
class PercentValueType implements FactValueTypeInterface
{
    private const int SCALE = 1;

    public function __construct(
        private readonly NumberFormatter $numberFormatter,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::PERCENT;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        $formatted = array_map(
            $this->formatPercent(...),
            explode(FactValueType::RANGE_SEPARATOR, (string)$fact->value, 2)
        );
        if (2 === \count($formatted)) {
            return $this->translator->trans(
                'oro_smart_brief.range',
                ['%from%' => $formatted[0], '%to%' => $formatted[1]]
            );
        }

        return $formatted[0];
    }

    #[\Override]
    public function canonicalize(mixed $value): string
    {
        return implode(FactValueType::RANGE_SEPARATOR, array_map(
            static fn (string $percent) => (string)BigDecimal::of($percent)
                ->toScale(self::SCALE, RoundingMode::HALF_UP),
            explode(FactValueType::RANGE_SEPARATOR, (string)$value, 2)
        ));
    }

    #[\Override]
    public function restore(string|int|array $canonical): string
    {
        return (string)$canonical;
    }

    private function formatPercent(string $value): string
    {
        $percent = BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HALF_UP);
        $formatted = (string)$this->numberFormatter->formatPercent(
            (string)$percent->withPointMovedLeft(2),
            [
                \NumberFormatter::MIN_FRACTION_DIGITS => self::SCALE,
                \NumberFormatter::MAX_FRACTION_DIGITS => self::SCALE,
            ]
        );

        return $percent->isPositive() ? '+' . $formatted : $formatted;
    }
}
