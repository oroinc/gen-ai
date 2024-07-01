<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

/**
 * Task makes provided text smaller
 */
class ShortenTextTask extends AbstractSimpleTask implements TaskInterface
{
    public function getKey(): string
    {
        return 'shorten';
    }
}
