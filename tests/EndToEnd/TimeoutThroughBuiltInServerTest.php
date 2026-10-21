<?php

use Fanswoo\CloudTasksGuard\Tests\Fixtures\BusyTimeoutJob;
use Fanswoo\CloudTasksGuard\Tests\Fixtures\MemoryExhaustingJob;
use Fanswoo\CloudTasksGuard\Tests\Support\BuiltInServer;
use Fanswoo\CloudTasksGuard\Tests\Support\DispatchedTask;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;

/**
 * Deliver the task to the built-in server the way Cloud Tasks does.
 */
function deliverOverHttp(BuiltInServer $server, DispatchedTask $task, int $retryCount = 0, int $executionCount = 0): Response
{
    return Http::withHeaders($task->headers($retryCount, $executionCount))
        ->withBody($task->body(), 'application/json')
        ->timeout(30)
        ->post($server->url('handle-task'));
}

/**
 * Turn a payload that the server recorded back into a task that can be delivered.
 *
 * @param  array<string, mixed>  $payload
 */
function releasedTask(DispatchedTask $original, array $payload): DispatchedTask
{
    $task = new Task;
    $task->mergeFrom($original->task);
    $task->setName($original->task->getName().'-released');
    $task->getHttpRequest()->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

    return new DispatchedTask($task);
}

beforeEach(function () {
    $this->server = null;
});

afterEach(function () {
    $this->server?->stop();
});

it('answers 200 after a real timeout, and the job runs exactly as often as its tries', function () {
    $this->server = BuiltInServer::start();
    $task = $this->dispatchToCloudTasks(new BusyTimeoutJob($this->server->stateDirectory, tries: 2));

    $response = deliverOverHttp($this->server, $task);

    expect($response->status())->toBe(200, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe(['run attempts=1'])
        ->and($this->server->createdTaskPayloads())->toHaveCount(1)
        ->and($this->server->failedJobs())->toBeEmpty();

    [$releasedPayload] = $this->server->createdTaskPayloads();

    expect($releasedPayload['uuid'])->toBe($task->uuid())
        ->and($releasedPayload['internal']['attempts'])->toBe(1);

    // Cloud Tasks deletes the original task after the 200 and delivers only the
    // released copy, a new task whose counters start at zero.
    $response = deliverOverHttp($this->server, releasedTask($task, $releasedPayload));

    expect($response->status())->toBe(200, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe([
            'run attempts=1',
            'run attempts=2',
            'failed '.TimeoutExceededException::class,
        ])
        ->and($this->server->createdTaskPayloads())->toHaveCount(1)
        ->and($this->server->failedJobs())->toHaveCount(1);
});

it('answers 200 after a real timeout fails the job on its last try', function () {
    $this->server = BuiltInServer::start();
    $task = $this->dispatchToCloudTasks(new BusyTimeoutJob($this->server->stateDirectory, tries: 1));

    $response = deliverOverHttp($this->server, $task);

    expect($response->status())->toBe(200, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe(['run attempts=1', 'failed '.TimeoutExceededException::class])
        ->and($this->server->createdTaskPayloads())->toBeEmpty()
        ->and($this->server->failedJobs())->toHaveCount(1);
});

it('ends a real timeout with 500 beside the released copy without the guard', function () {
    $this->server = BuiltInServer::start(['CLOUD_TASKS_GUARD_E2E_ANSWER_HANDLED_TIMEOUTS' => 'false']);
    $task = $this->dispatchToCloudTasks(new BusyTimeoutJob($this->server->stateDirectory, tries: 2));

    $response = deliverOverHttp($this->server, $task);

    // Cloud Tasks would deliver the original task again, and the released copy as well.
    expect($response->status())->toBe(500, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe(['run attempts=1'])
        ->and($this->server->createdTaskPayloads())->toHaveCount(1);
});

it('fails a job over HTTP once the deliveries that crashed in it use up its tries', function () {
    $this->server = BuiltInServer::start();
    $task = $this->dispatchToCloudTasks(new MemoryExhaustingJob($this->server->stateDirectory, tries: 2));

    // Each delivery ends in a fatal error after the job started, so Cloud Tasks
    // gets a 500 and delivers the same task again.
    $first = deliverOverHttp($this->server, $task, retryCount: 0, executionCount: 0);
    $second = deliverOverHttp($this->server, $task, retryCount: 1, executionCount: 0);
    $third = deliverOverHttp($this->server, $task, retryCount: 2, executionCount: 0);

    expect($first->status())->toBe(500, $this->server->diagnostics())
        ->and($second->status())->toBe(500, $this->server->diagnostics())
        ->and($third->status())->toBe(200, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe([
            'run attempts=1',
            'run attempts=2',
            'failed '.MaxAttemptsExceededException::class,
        ])
        ->and($this->server->createdTaskPayloads())->toBeEmpty()
        ->and($this->server->failedJobs())->toHaveCount(1);
});

it('does not count deliveries over HTTP that failed before the job started', function () {
    $this->server = BuiltInServer::start();
    $task = $this->dispatchToCloudTasks(new MemoryExhaustingJob($this->server->stateDirectory, tries: 2));

    // The first two deliveries ended in a 5xx before they reached the application.
    $first = deliverOverHttp($this->server, $task, retryCount: 2, executionCount: 0);
    $second = deliverOverHttp($this->server, $task, retryCount: 3, executionCount: 0);
    $third = deliverOverHttp($this->server, $task, retryCount: 4, executionCount: 0);

    expect($first->status())->toBe(500, $this->server->diagnostics())
        ->and($second->status())->toBe(500, $this->server->diagnostics())
        ->and($third->status())->toBe(200, $this->server->diagnostics())
        ->and($this->server->jobLog())->toBe([
            'run attempts=1',
            'run attempts=2',
            'failed '.MaxAttemptsExceededException::class,
        ])
        ->and($this->server->failedJobs())->toHaveCount(1);
});
