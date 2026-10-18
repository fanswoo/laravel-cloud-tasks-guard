<?php

use Fanswoo\CloudTasksGuard\Tests\Fixtures\RecordedJob;
use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Fanswoo\CloudTasksGuard\Tests\Support\TestEnvironment;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksQueue;

it('leaves the attempts alone on a first delivery', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task, retryCount: 0, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
});

it('adds the deliveries that never got an answer to the attempts', function (int $retryCount, int $executionCount, int $expectedAttempts) {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));

    $this->deliver($task, $retryCount, $executionCount)->assertOk();

    expect(JobRecorder::$runs)->toBe([$expectedAttempts]);
})->with([
    'one unanswered delivery' => [1, 0, 2],
    'three unanswered deliveries' => [3, 0, 4],
    'three retries, one answered by the handler' => [3, 1, 3],
    'every retry answered by the handler' => [2, 2, 1],
]);

it('counts nothing when a counter header is missing or not a number', function (?string $retryCount, ?string $executionCount) {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

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

it('does not count the deliveries of the original task again for a released copy', function () {
    JobRecorder::script(JobRecorder::THROW);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    // The original task crashed once before the delivery that released the job.
    $this->deliver($task, retryCount: 1, executionCount: 0)->assertOk();

    $copies = $this->releasedCopiesOf($task);

    expect(JobRecorder::$runs)->toBe([2])
        ->and($copies)->toHaveCount(1)
        ->and($copies[0]->attempts())->toBe(2);

    // The released copy is a new task, so its counters start at zero again.
    $this->deliver($copies[0], retryCount: 0, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([2, 3])
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

    $this->deliver($task, retryCount: 5, executionCount: 0)->assertOk();

    expect(JobRecorder::$runs)->toBe([1]);
});

it('only counts deliveries that the task handler processes', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 10));

    // Process the job the way the Cloud Run job command does, without the task
    // handler, while a request with delivery counters is bound.
    $this->app->instance('request', Request::create('/', 'POST', server: $this->transformHeadersToServerVars($task->headers(4, 0))));

    $queue = tap(new CloudTasksQueue(config('queue.connections.'.TestEnvironment::CONNECTION), app(CloudTasksClient::class)))
        ->setConnectionName(TestEnvironment::CONNECTION);

    $job = new CloudTasksJob(app(), $queue, $task->payload(), TestEnvironment::CONNECTION, TestEnvironment::QUEUE);
    $job->setAttempts($job->attempts() + 1);

    app('cloud-tasks.worker')->process(TestEnvironment::CONNECTION, $job, new WorkerOptions);

    expect(JobRecorder::$runs)->toBe([1]);
});
