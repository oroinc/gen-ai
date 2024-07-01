<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

/**
 * Task corrects grammar in the provided text
 */
class CorrectGrammarTask extends AbstractSimpleTask implements TaskInterface
{
    public function getKey(): string
    {
        return 'correct_grammar';
    }
}
