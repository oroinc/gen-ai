<?php

namespace Oro\Bundle\AiContentGenerationBundle\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Exception\TaskNotFoundException;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\OpenPromptTaskInterface;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;

/**
 * Provides Content AI Generation Tasks by parameters
 */
class TasksProvider
{
    /**
     * @param TaskInterface[] $tasks
     */
    public function __construct(private readonly iterable $tasks)
    {
    }

    public function getTasks(UserContentGenerationRequest $request): \Iterator
    {
        foreach ($this->tasks as $task) {
            if ($task->supports($request)) {
                yield $task;
            }
        }
    }

    public function getOpenPromptTaskKeys(): array
    {
        $result = [];

        foreach ($this->tasks as $task) {
            if ($task instanceof OpenPromptTaskInterface) {
                $result[] = $task->getKey();
            }
        }

        return $result;
    }

    public function getTaskFormPredefinedContent(string $taskKey, UserContentGenerationRequest $request): string
    {
        $task = $this->getTask($taskKey);

        if (!$task instanceof OpenPromptTaskInterface) {
            throw new TaskNotFoundException(sprintf('There is no open prompt task %s', $taskKey));
        }

        return $this->convertContextItemsToText($task->getFormPredefinedContent($request));
    }

    public function getTask(string $key): TaskInterface
    {
        foreach ($this->tasks as $task) {
            if ($task->getKey() === $key) {
                return $task;
            }
        }

        throw new TaskNotFoundException(sprintf('There is no task with key = "%s"', $key));
    }

    /**
     * @param array<int, ContextItem> $contextItems
     */
    private function convertContextItemsToText(array $contextItems): string
    {
        $validTextRepresentations = array_filter(array_map(
            fn (ContextItem $contextItem) => $contextItem->getTextRepresentation(),
            $contextItems
        ));

        return ucfirst(implode(', ', $validTextRepresentations));
    }
}
