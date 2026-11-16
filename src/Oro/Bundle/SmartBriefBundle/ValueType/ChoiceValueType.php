<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One of a fact's fixed choices (e.g. the contact channel), shown through
 * `oro_smart_brief.fact.{key}.choice.{value}`.
 */
class ChoiceValueType implements FactValueTypeInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::CHOICE;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return $this->translator->trans(sprintf('oro_smart_brief.fact.%s.choice.%s', $fact->key, (string)$fact->value));
    }

    #[\Override]
    public function canonicalize(mixed $value): string
    {
        return (string)$value;
    }

    #[\Override]
    public function restore(string|int|array $canonical): string
    {
        return (string)$canonical;
    }
}
