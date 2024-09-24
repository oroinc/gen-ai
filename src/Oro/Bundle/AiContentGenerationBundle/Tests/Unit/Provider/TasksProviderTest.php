<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Provider;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Exception\TaskNotFoundException;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\OpenPromptTaskInterface;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TasksProviderTest extends TestCase
{
    private TaskInterface&MockObject $task1;

    private TaskInterface&MockObject $task2;

    private UserContentGenerationRequest $request;

    private TasksProvider $tasksProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->task1 = $this->createMock(TaskInterface::class);
        $this->task2 = $this->createMock(OpenPromptTaskInterface::class);
        $this->tasks = [$this->task1, $this->task2];
        $this->request = new UserContentGenerationRequest('', [], '', []);
        $this->tasksProvider = new TasksProvider($this->tasks);
    }

    public function testGetTasks(): void
    {
        $this->task1
            ->expects(self::once())
            ->method('supports')
            ->willReturn(true);
        $this->task2
            ->expects(self::once())
            ->method('supports')
            ->willReturn(false);

        $tasks = iterator_to_array($this->tasksProvider->getTasks($this->request));

        self::assertCount(1, $tasks);
        self::assertEquals($this->task1, $tasks[0]);
    }

    public function testGetOpenPromptTaskKeys(): void
    {
        $this->task2
            ->expects(self::once())
            ->method('getKey')
            ->willReturn('task2');

        $this->tasks = [$this->task1, $this->task2];
        $this->tasksProvider = new TasksProvider($this->tasks);

        $keys = $this->tasksProvider->getOpenPromptTaskKeys();

        self::assertCount(1, $keys);
        self::assertEquals('task2', $keys[0]);
    }

    public function testGetTaskFormPredefinedContent(): void
    {
        $this->task2
            ->expects(self::once())
            ->method('getKey')
            ->willReturn('task2');

        $this->task2
            ->expects(self::once())
            ->method('getFormPredefinedContent')
            ->willReturn([new ContextItem('key', 'value')]);

        $this->tasks = [$this->task1, $this->task2];
        $this->tasksProvider = new TasksProvider($this->tasks);

        $content = $this->tasksProvider->getTaskFormPredefinedContent('task2', $this->request);

        self::assertEquals('Key value', $content);
    }

    public function testGetTaskFormPredefinedContentThrowsException(): void
    {
        self::expectException(TaskNotFoundException::class);
        $this->tasksProvider->getTaskFormPredefinedContent('non_existent_task', $this->request);
    }

    public function testGetTask(): void
    {
        $this->task1
            ->expects(self::once())
            ->method('getKey')
            ->willReturn('task1');

        $task = $this->tasksProvider->getTask('task1');

        self::assertEquals($this->task1, $task);
    }

    public function testGetTaskThrowsException(): void
    {
        self::expectException(TaskNotFoundException::class);
        $this->tasksProvider->getTask('non_existent_task');
    }
}
