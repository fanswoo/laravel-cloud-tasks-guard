<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Fanswoo\CloudTasksGuard\DeliveryCounters;
use Fanswoo\CloudTasksGuard\TaskIdentity;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Support\Str;

/**
 * A task that the stackkit queue created, as Cloud Tasks would deliver it.
 */
final readonly class DispatchedTask
{
    public function __construct(public Task $task) {}

    /**
     * The request body that Cloud Tasks posts to the task handler.
     */
    public function body(): string
    {
        return (string) $this->task->getHttpRequest()?->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function uuid(): string
    {
        return $this->payload()['uuid'];
    }

    /**
     * The attempt count that the payload carries.
     */
    public function attempts(): int
    {
        return $this->payload()['internal']['attempts'] ?? 0;
    }

    /**
     * The short task name that Cloud Tasks sends in the X-CloudTasks-TaskName header.
     */
    public function shortName(): string
    {
        return Str::afterLast($this->task->getName(), '/');
    }

    /**
     * The identity that a delivery of this task carries in its headers.
     */
    public function identity(): TaskIdentity
    {
        return new TaskIdentity(TestEnvironment::QUEUE, $this->shortName());
    }

    /**
     * The headers of a delivery of this task. A null counter leaves its header out.
     *
     * @return array<string, string>
     */
    public function headers(int|string|null $retryCount = 0, int|string|null $executionCount = 0): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            TaskIdentity::QUEUE_NAME_HEADER => TestEnvironment::QUEUE,
            TaskIdentity::TASK_NAME_HEADER => $this->shortName(),
        ];

        if ($retryCount !== null) {
            $headers[DeliveryCounters::RETRY_COUNT_HEADER] = (string) $retryCount;
        }

        if ($executionCount !== null) {
            $headers[DeliveryCounters::EXECUTION_COUNT_HEADER] = (string) $executionCount;
        }

        return $headers;
    }
}
