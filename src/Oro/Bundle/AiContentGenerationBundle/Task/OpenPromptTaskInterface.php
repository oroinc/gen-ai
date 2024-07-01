<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Represents task with user predefined content that AI Client should process
 */
interface OpenPromptTaskInterface extends TaskInterface
{
    /**
     * @return ContextItem[]
     */
    public function getFormPredefinedContent(UserContentGenerationRequest $contentGenerationRequest): array;
}
