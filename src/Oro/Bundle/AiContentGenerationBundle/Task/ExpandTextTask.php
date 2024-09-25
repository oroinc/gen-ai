<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

/**
 * Task makes provided text bigger
 */
class ExpandTextTask extends AbstractSimpleTask implements TaskInterface
{
    #[\Override]
    public function getKey(): string
    {
        return 'expand';
    }
}
