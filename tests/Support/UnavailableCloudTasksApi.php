<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Google\Cloud\Tasks\V2\Task;
use RuntimeException;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApiFake;

/**
 * A Cloud Tasks API fake that knows every delivered task but becomes unable to
 * create tasks, as when the API is unavailable while the worker releases a job.
 */
final class UnavailableCloudTasksApi extends CloudTasksApiFake
{
    /**
     * @param  int  $successfulCreates  The number of tasks it creates before it fails.
     */
    public function __construct(private int $successfulCreates = 0) {}

    public function createTask(string $queueName, Task $task): Task
    {
        if ($this->successfulCreates-- > 0) {
            return parent::createTask($queueName, $task);
        }

        throw new RuntimeException('The Cloud Tasks API is unavailable.');
    }

    public function exists(string $taskName): bool
    {
        return true;
    }
}
