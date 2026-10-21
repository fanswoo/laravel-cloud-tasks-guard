<?php

use Fanswoo\CloudTasksGuard\StartedDeliveries;
use Fanswoo\CloudTasksGuard\TaskIdentity;
use Fanswoo\CloudTasksGuard\Tests\Fixtures\RecordedJob;
use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Fanswoo\CloudTasksGuard\Tests\Support\TestEnvironment;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

it('leaves the attempts alone on a first delivery', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task, retryCount: 0, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
});

it('does not count deliveries that failed before the job started', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    // Three deliveries ended in a 5xx before the job started, for example a 503
    // while no instance was ready, so none of them reached the task handler.
    $this->deliver($task, retryCount: 3, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1])
        ->and(JobRecorder::$failures)->toBeEmpty();
});

it('adds the earlier deliveries that started the job and never got an answer to the attempts', function (int $retryCount, int $executionCount, int $startedBefore, int $expectedAttempts) {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));
    $this->recordStartedDeliveries($task, $startedBefore);

    $this->deliver($task, $retryCount, $executionCount)->assertOk();

    expect(JobRecorder::$runs)->toBe([$expectedAttempts]);
})->with([
    'one crash' => [1, 0, 1, 2],
    'three crashes' => [3, 0, 3, 4],
    'two failures before the job started, then one crash' => [3, 0, 1, 2],
    'one crash, then two failures before the job started' => [3, 0, 1, 2],
    'never more than the unanswered deliveries' => [1, 0, 3, 2],
    'every earlier delivery answered by the handler before the job started' => [2, 2, 0, 1],
    'an answer before the job started, then two crashes, counts one crash' => [3, 1, 2, 2],
]);

it('counts nothing when a counter header is missing or not a number', function (?string $retryCount, ?string $executionCount) {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));
    $this->recordStartedDeliveries($task, 5);

    $this->deliver($task, $retryCount, $executionCount)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
})->with([
    'both headers missing' => [null, null],
    'retry count missing' => [null, '0'],
    'execution count missing' => ['2', null],
    'empty retry count' => ['', '0'],
    'word' => ['two', '0'],
    'negative number' => ['-1', '0'],
    'decimal number' => ['1.5', '0'],
    'exponent' => ['1e3', '0'],
    'hexadecimal number' => ['0x2', '0'],
    'leading zero' => ['02', '0'],
    'too many digits to fit an integer' => ['99999999999999999999', '0'],
    'garbled execution count' => ['2', 'many'],
    'more executions than retries' => ['1', '3'],
]);

it('counts nothing when the queue name or the task name is missing or malformed', function (?string $queueName, ?string $taskName) {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));
    $taskName = $taskName === '{task}' ? $task->shortName() : $taskName;

    $headers = $task->headers(retryCount: 3, executionCount: 0);
    unset($headers[TaskIdentity::QUEUE_NAME_HEADER], $headers[TaskIdentity::TASK_NAME_HEADER]);

    if ($queueName !== null) {
        $headers[TaskIdentity::QUEUE_NAME_HEADER] = $queueName;
    }

    if ($taskName === null) {
        // The task handler also finds the task by the header of an App Engine target.
        $headers['X-AppEngine-TaskName'] = $task->shortName();
    } else {
        $headers[TaskIdentity::TASK_NAME_HEADER] = $taskName;

        // The task handler checks that a task with the name in the header exists.
        $this->cloudTasks()->createTask('', (new Task)->setName(CloudTasksClient::taskName(
            TestEnvironment::PROJECT, TestEnvironment::LOCATION, TestEnvironment::QUEUE, $taskName,
        )));
    }

    // Three earlier deliveries started the job under the names that the headers carry.
    $this->recordStartedDeliveries(new TaskIdentity($queueName ?? TestEnvironment::QUEUE, $taskName ?? $task->shortName()), 3);

    $this->deliverWithHeaders($task, $headers)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
})->with([
    'task name missing' => [TestEnvironment::QUEUE, null],
    'queue name missing' => [null, '{task}'],
    'task name with a space' => [TestEnvironment::QUEUE, 'a task'],
    'task name with a dot' => [TestEnvironment::QUEUE, 'task.1'],
    'task name longer than 500 characters' => [TestEnvironment::QUEUE, str_repeat('t', 501)],
    'empty queue name' => ['', '{task}'],
    'queue name with an underscore' => ['guard_queue', '{task}'],
    'queue name longer than 100 characters' => [str_repeat('q', 101), '{task}'],
]);

it('fails a job that crashed as many times as its tries on the next delivery', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    // Each crash leaves Cloud Tasks without an answer, so it delivers the same
    // payload again with a higher retry count and the same execution count.
    $this->deliver($task, retryCount: 0, executionCount: 0);
    $this->deliver($task, retryCount: 1, executionCount: 0);
    $this->deliver($task, retryCount: 2, executionCount: 0);

    expect(JobRecorder::$runs)->toBe([1, 2, 3])
        ->and(JobRecorder::$failures)->toBeEmpty();

    $this->deliver($task, retryCount: 3, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 2, 3])
        ->and(JobRecorder::$failures)->toHaveCount(1)
        ->and(JobRecorder::$failures[0])->toBeInstanceOf(MaxAttemptsExceededException::class);

    $this->assertDatabaseCount('failed_jobs', 1);
    expect($this->releasedCopiesOf($task))->toBeEmpty();
});

it('counts only the crashes among deliveries that failed before the job started', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    // Deliveries 1 and 2 ended in a 5xx before the job started. Delivery 3 crashed.
    $this->deliver($task, retryCount: 2, executionCount: 0);

    // Delivery 4 ended in a 5xx before the job started. Deliveries 5 and 6 crashed.
    $this->deliver($task, retryCount: 4, executionCount: 0);
    $this->deliver($task, retryCount: 5, executionCount: 0);

    expect(JobRecorder::$runs)->toBe([1, 2, 3])
        ->and(JobRecorder::$failures)->toBeEmpty();

    $this->deliver($task, retryCount: 6, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 2, 3])
        ->and(JobRecorder::$failures)->toHaveCount(1)
        ->and(JobRecorder::$failures[0])->toBeInstanceOf(MaxAttemptsExceededException::class);

    $this->assertDatabaseCount('failed_jobs', 1);
});

it('does not count the deliveries of the original task again for a released copy', function () {
    JobRecorder::script(JobRecorder::SUCCEED, JobRecorder::THROW);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 5));

    // The first delivery of the original task crashed, and the second one released the job.
    $this->deliver($task, retryCount: 0, executionCount: 0);
    $this->deliver($task, retryCount: 1, executionCount: 0)->assertOk();

    $copies = $this->releasedCopiesOf($task);

    expect(JobRecorder::$runs)->toBe([1, 2])
        ->and($copies)->toHaveCount(1)
        ->and($copies[0]->attempts())->toBe(2);

    // The released copy is a new task, so its counters and its started deliveries
    // start at zero again. Its first delivery crashes, and its second delivery
    // ends in a 5xx before the job started.
    $this->deliver($copies[0], retryCount: 0, executionCount: 0)->assertOk();
    $this->deliver($copies[0], retryCount: 2, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 2, 3, 4])
        ->and(JobRecorder::$failures)->toBeEmpty();
});

it('fails a released copy that crashed once its tries are used up', function () {
    JobRecorder::script(JobRecorder::THROW, JobRecorder::SUCCEED, JobRecorder::THROW);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task, retryCount: 0, executionCount: 0);
    [$copy] = $this->releasedCopiesOf($task);

    // The first delivery of the copy crashes, so Cloud Tasks delivers it again.
    $this->deliver($copy, retryCount: 0, executionCount: 0);
    $this->deliver($copy, retryCount: 1, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 2, 3])
        ->and(JobRecorder::$failures)->toHaveCount(1)
        ->and($this->releasedCopiesOf($task))->toHaveCount(1);

    $this->assertDatabaseCount('failed_jobs', 1);
});

it('leaves the attempts alone when the guard is disabled', function () {
    config(['cloud-tasks-guard.count_unanswered_deliveries' => false]);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));
    $this->recordStartedDeliveries($task, 5);

    $this->deliver($task, retryCount: 5, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
});

it('records no started delivery while the guard is disabled', function () {
    config(['cloud-tasks-guard.count_unanswered_deliveries' => false]);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task, retryCount: 0, executionCount: 0);

    config(['cloud-tasks-guard.count_unanswered_deliveries' => true]);

    $this->deliver($task, retryCount: 1, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 1]);
});

it('only counts deliveries that the task handler processes', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));
    $this->recordStartedDeliveries($task, 4);

    // Process the job the way the Cloud Run job command does, without the task
    // handler, while a request with delivery counters is bound.
    $this->app->instance('request', Request::create('/', 'POST', server: $this->transformHeadersToServerVars($task->headers(4, 0))));

    $queue = tap(new CloudTasksQueue(config('queue.connections.'.TestEnvironment::CONNECTION), app(CloudTasksClient::class)))
        ->setConnectionName(TestEnvironment::CONNECTION);

    $job = new CloudTasksJob(app(), $queue, $task->payload(), TestEnvironment::CONNECTION, TestEnvironment::QUEUE);
    $job->setAttempts($job->attempts() + 1);

    app('cloud-tasks.worker')->process(TestEnvironment::CONNECTION, $job, new WorkerOptions);

    expect(JobRecorder::$runs)->toBe([1])
        ->and(app(StartedDeliveries::class)->record($task->identity()))->toBe(4);
});

it('keeps the started deliveries in the configured cache store', function () {
    config([
        'cache.stores.started-deliveries' => ['driver' => 'array'],
        'cloud-tasks-guard.started_deliveries_store' => 'started-deliveries',
    ]);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));

    $this->deliver($task, retryCount: 0, executionCount: 0);
    $this->deliver($task, retryCount: 1, executionCount: 0);

    // The default store does not hold the count.
    Cache::store()->flush();

    $this->deliver($task, retryCount: 2, executionCount: 0);

    expect(JobRecorder::$runs)->toBe([1, 2, 3]);

    // The configured store does.
    Cache::store('started-deliveries')->flush();

    $this->deliver($task, retryCount: 3, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 2, 3, 1]);
});

it('counts the started deliveries in the store', function (string $store) {
    $cacheDirectory = sys_get_temp_dir().'/cloud-tasks-guard-cache-'.bin2hex(random_bytes(8));

    config([
        'cache.stores.file' => ['driver' => 'file', 'path' => $cacheDirectory],
        'cache.stores.database' => ['driver' => 'database', 'table' => 'cache'],
        'cloud-tasks-guard.started_deliveries_store' => $store,
    ]);

    try {
        $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

        $this->deliver($task, retryCount: 0, executionCount: 0);
        $this->deliver($task, retryCount: 1, executionCount: 0);
        $this->deliver($task, retryCount: 2, executionCount: 0);
        $this->deliver($task, retryCount: 3, executionCount: 0)->assertOk();

        expect(JobRecorder::$runs)->toBe([1, 2, 3])
            ->and(JobRecorder::$failures)->toHaveCount(1)
            ->and(JobRecorder::$failures[0])->toBeInstanceOf(MaxAttemptsExceededException::class);
    } finally {
        (new Filesystem)->deleteDirectory($cacheDirectory);
    }
})->with(['array', 'file', 'database']);

it('counts nothing and still processes the job when the cache store fails', function (string $store) {
    config([
        'cache.stores.broken-database' => ['driver' => 'database', 'table' => 'missing_cache_table'],
        'cloud-tasks-guard.started_deliveries_store' => $store,
    ]);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task, retryCount: 0, executionCount: 0)->assertOk();
    $this->deliver($task, retryCount: 1, executionCount: 0)->assertOk();
    $this->deliver($task, retryCount: 2, executionCount: 0)->assertOk();
    $this->deliver($task, retryCount: 3, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1, 1, 1, 1])
        ->and(JobRecorder::$failures)->toBeEmpty();
})->with([
    'a store that is not defined' => 'missing-store',
    'a store whose table does not exist' => 'broken-database',
]);
