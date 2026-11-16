<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub;

use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;

/**
 * A project value type whose canonical form is an associative array emitted as given.
 */
final class ProjectArrayValueTypeStub implements FactValueTypeInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'project_money';
    }

    #[\Override]
    public function format(Fact $fact, bool $forDisplay = false): string
    {
        return json_encode($fact->value);
    }

    #[\Override]
    public function canonicalize(mixed $value): array
    {
        return (array)$value;
    }

    #[\Override]
    public function restore(string|int|array $canonical): array
    {
        return (array)$canonical;
    }
}
