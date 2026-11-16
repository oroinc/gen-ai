<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Oro\Bundle\CurrencyBundle\Entity\Price;
use Oro\Bundle\LocaleBundle\Formatter\NumberFormatter;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;

/**
 * An amount with its currency, Oro's {@see Price}, formatted as currency; the canonical form is the scale-4 amount
 * plus the currency code.
 */
class MoneyValueType implements FactValueTypeInterface
{
    private const int CANONICAL_SCALE = 4;

    public function __construct(
        private readonly NumberFormatter $numberFormatter
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::MONEY;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        $money = $this->toMoney($fact->value);

        return (string)$this->numberFormatter->formatCurrency($money->getValue(), $money->getCurrency());
    }

    /**
     * @return array{amount: string, currency: string}
     */
    #[\Override]
    public function canonicalize(mixed $value): array
    {
        $money = $this->toMoney($value);

        return [
            'amount' => (string)BigDecimal::of((string)$money->getValue())
                ->toScale(self::CANONICAL_SCALE, RoundingMode::HALF_UP),
            'currency' => $money->getCurrency(),
        ];
    }

    #[\Override]
    public function restore(string|int|array $canonical): Price
    {
        if (!\is_array($canonical) || !isset($canonical['amount'], $canonical['currency'])) {
            throw new \InvalidArgumentException('A canonical money value needs an amount and a currency.');
        }

        return Price::create((string)$canonical['amount'], (string)$canonical['currency']);
    }

    private function toMoney(mixed $value): Price
    {
        if (!$value instanceof Price) {
            throw new \InvalidArgumentException(sprintf(
                'A money fact value must be an instance of %s, %s given.',
                Price::class,
                get_debug_type($value)
            ));
        }

        return $value;
    }
}
