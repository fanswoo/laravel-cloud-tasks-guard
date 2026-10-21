<?php

use Fanswoo\CloudTasksGuard\TaskIdentity;
use Illuminate\Http\Request;

function requestWithNames(?string $queueName, ?string $taskName): Request
{
    $request = Request::create('/handle-task', 'POST');

    if ($queueName !== null) {
        $request->headers->set(TaskIdentity::QUEUE_NAME_HEADER, $queueName);
    }

    if ($taskName !== null) {
        $request->headers->set(TaskIdentity::TASK_NAME_HEADER, $taskName);
    }

    return $request;
}

it('reads the queue name and the task name from the request', function () {
    $identity = TaskIdentity::fromRequest(requestWithNames('guard-queue', '01K7ZQ8V2M3N4P5Q6R7S8T9V0W-RecordedJob'));

    expect($identity)->not->toBeNull()
        ->and($identity->queueName)->toBe('guard-queue')
        ->and($identity->taskName)->toBe('01K7ZQ8V2M3N4P5Q6R7S8T9V0W-RecordedJob')
        ->and($identity->identifier())->toBe('guard-queue/01K7ZQ8V2M3N4P5Q6R7S8T9V0W-RecordedJob');
});

it('accepts a system-generated task ID and underscores in a task name', function (string $taskName) {
    expect(TaskIdentity::fromRequest(requestWithNames('guard-queue', $taskName))?->taskName)->toBe($taskName);
})->with([
    'system-generated ID' => '7139834521953042321',
    'underscores' => 'send_invoice_42',
]);

it('accepts the longest names that Cloud Tasks allows', function () {
    $identity = TaskIdentity::fromRequest(requestWithNames(str_repeat('q', 100), str_repeat('t', 500)));

    expect($identity?->queueName)->toBe(str_repeat('q', 100))
        ->and($identity?->taskName)->toBe(str_repeat('t', 500));
});

it('reads header names case-insensitively and ignores surrounding spaces', function () {
    $request = Request::create('/handle-task', 'POST');
    $request->headers->set('x-cloudtasks-queuename', ' guard-queue ');
    $request->headers->set('X-CLOUDTASKS-TASKNAME', 'task-1');

    expect(TaskIdentity::fromRequest($request)?->identifier())->toBe('guard-queue/task-1');
});

it('returns no identity when a name is missing or not a Cloud Tasks ID', function (?string $queueName, ?string $taskName) {
    expect(TaskIdentity::fromRequest(requestWithNames($queueName, $taskName)))->toBeNull();
})->with([
    'both missing' => [null, null],
    'queue name missing' => [null, 'task-1'],
    'task name missing' => ['guard-queue', null],
    'empty queue name' => ['', 'task-1'],
    'empty task name' => ['guard-queue', ''],
    'queue name with an underscore' => ['guard_queue', 'task-1'],
    'full queue resource name' => ['projects/guard-project/locations/europe-west1/queues/guard-queue', 'task-1'],
    'full task resource name' => ['guard-queue', 'projects/guard-project/locations/europe-west1/queues/guard-queue/tasks/task-1'],
    'task name with a space' => ['guard-queue', 'task 1'],
    'task name with a dot' => ['guard-queue', 'task.1'],
    'task name with a non-ASCII letter' => ['guard-queue', 'tâche-1'],
    'task name with a line break inside' => ['guard-queue', "task\n1"],
    'list of task names' => ['guard-queue', 'task-1, task-2'],
    'queue name too long' => [str_repeat('q', 101), 'task-1'],
    'task name too long' => ['guard-queue', str_repeat('t', 501)],
]);
