<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;

/**
 * A project value type: canonical form is the upper-cased SKU.
 */
final class ProjectSkuValueTypeStub implements FactValueTypeInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'project_sku';
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return (string)$fact->value;
    }

    #[\Override]
    public function canonicalize(mixed $value): string
    {
        return strtoupper((string)$value);
    }

    #[\Override]
    public function restore(string|int|array $canonical): string
    {
        return (string)$canonical;
    }
}
