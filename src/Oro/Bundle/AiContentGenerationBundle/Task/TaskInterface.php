<?php

namespace Oro\Bundle\AiContentGenerationBundle\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;

/**
 * Represents task that AI Client should process
 */
interface TaskInterface
{
    public function supports(UserContentGenerationRequest $contentGenerationRequest): bool;

    public function getContentGenerationPhraseTranslationKey(): string;

    public function getKey(): string;

    /**
     * @return ContextItem[]
     */
    public function getContext(UserContentGenerationRequest $contentGenerationRequest): array;
}
