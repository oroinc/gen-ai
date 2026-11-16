<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An enum option id (e.g. `quote_internal_status.sent_to_customer`), shown as the option's translated label.
 */
class EnumOptionValueType implements FactValueTypeInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return FactValueType::ENUM_OPTION;
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return $this->translator->trans(ExtendHelper::buildEnumOptionTranslationKey((string)$fact->value));
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
