<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Google\Cloud\Tasks\V2\Task;
use RuntimeException;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApiFake;

/**
 * A Cloud Tasks API fake that knows every delivered task but cannot create tasks,
 * as when the API is unavailable while the worker releases a job.
 */
final class UnavailableCloudTasksApi extends CloudTasksApiFake
{
    public function createTask(string $queueName, Task $task): Task
    {
        throw new RuntimeException('The Cloud Tasks API is unavailable.');
    }

    public function exists(string $taskName): bool
    {
        return true;
    }
}
