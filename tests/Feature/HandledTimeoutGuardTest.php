<?php

use Fanswoo\CloudTasksGuard\Guards\HandledTimeoutGuard;
use Fanswoo\CloudTasksGuard\Tests\Fixtures\RecordedJob;
use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Fanswoo\CloudTasksGuard\Tests\Support\TimeoutSimulator;
use Fanswoo\CloudTasksGuard\Tests\Support\UnavailableCloudTasksApi;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Queue\TimeoutExceededException;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Symfony\Component\ErrorHandler\Error\FatalError;

beforeEach(function () {
    JobRecorder::script(JobRecorder::TIME_OUT);
});

it('answers 200 once the worker released the timed-out job as a new task', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task);

    $copies = $this->releasedCopiesOf($task);

    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(200)
        ->and($copies)->toHaveCount(1)
        ->and($copies[0]->attempts())->toBe(1)
        ->and(JobRecorder::$failures)->toBeEmpty();
});

it('answers 200 once the worker failed the timed-out job', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 1));

    $this->deliver($task);

    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(200)
        ->and(JobRecorder::$failures)->toHaveCount(1)
        ->and(JobRecorder::$failures[0])->toBeInstanceOf(TimeoutExceededException::class)
        ->and($this->releasedCopiesOf($task))->toBeEmpty();

    $this->assertDatabaseCount('failed_jobs', 1);
});

it('keeps the 500 when the released copy could not be created', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    CloudTasksApi::swap(new UnavailableCloudTasksApi);

    $this->deliver($task);

    // Cloud Tasks retries the original task, so the job is not lost.
    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(500)
        ->and(JobRecorder::$failures)->toBeEmpty();
});

it('keeps the 500 when the worker did not handle the timeout', function () {
    app(ExceptionHandler::class)->dontReport(FatalError::class);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task);

    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(500)
        ->and($this->releasedCopiesOf($task))->toBeEmpty()
        ->and(JobRecorder::$failures)->toBeEmpty();
});

it('keeps the 500 when the guard is disabled', function () {
    config(['cloud-tasks-guard.answer_handled_timeouts' => false]);

    $task = $this->dispatchToCloudTasks(new RecordedJob(tries: 3));

    $this->deliver($task);

    // The worker still released the job, so Cloud Tasks now holds two copies.
    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(500)
        ->and($this->releasedCopiesOf($task))->toHaveCount(1);
});

it('keeps the 500 for fatal errors other than the maximum execution time', function () {
    $task = $this->dispatchToCloudTasks(new RecordedJob(
        tries: 3,
        fatalErrorMessage: 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
    ));

    $this->deliver($task);

    expect(TimeoutSimulator::$response?->getStatusCode())->toBe(500)
        ->and($this->releasedCopiesOf($task))->toBeEmpty();
});

it('leaves a timeout outside the task handler to the exception handler', function () {
    $request = Request::create('/reports', 'POST');
    $error = TimeoutSimulator::fatalError();

    expect(app(HandledTimeoutGuard::class)->render($error, $request))->toBeNull()
        ->and(app(ExceptionHandler::class)->render($request, $error)->getStatusCode())->toBe(500);
});
