<?php

use Fanswoo\CloudTasksGuard\TaskDelivery;
use Illuminate\Http\Request;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;

function cloudTasksJob(): CloudTasksJob
{
    return (new ReflectionClass(CloudTasksJob::class))->newInstanceWithoutConstructor();
}

it('belongs to the request it began on', function () {
    $request = Request::create('/handle-task', 'POST');

    $delivery = TaskDelivery::begin($request);

    expect(TaskDelivery::of($request))->toBe($delivery)
        ->and(TaskDelivery::of(Request::create('/handle-task', 'POST')))->toBeNull()
        ->and(TaskDelivery::of(null))->toBeNull();
});

it('attaches only the first job it sees', function () {
    $delivery = TaskDelivery::begin(Request::create('/handle-task', 'POST'));
    $deliveredJob = cloudTasksJob();
    $otherJob = cloudTasksJob();

    expect($delivery->attach($deliveredJob))->toBeTrue()
        ->and($delivery->attach($deliveredJob))->toBeTrue()
        ->and($delivery->attach($otherJob))->toBeFalse()
        ->and($delivery->job())->toBe($deliveredJob)
        ->and($delivery->isFor($deliveredJob))->toBeTrue()
        ->and($delivery->isFor($otherJob))->toBeFalse();
});

it('counts the unanswered deliveries only once', function () {
    $delivery = TaskDelivery::begin(Request::create('/handle-task', 'POST'));

    expect($delivery->markUnansweredDeliveriesCounted())->toBeTrue()
        ->and($delivery->markUnansweredDeliveriesCounted())->toBeFalse();
});

it('treats a timeout as handled only after the failure or the release', function (bool $timedOut, bool $failureRecorded, bool $releasedTaskCreated, bool $handled) {
    $delivery = TaskDelivery::begin(Request::create('/handle-task', 'POST'));
    $delivery->attach(cloudTasksJob());

    if ($timedOut) {
        $delivery->markTimedOut();
    }

    if ($failureRecorded) {
        $delivery->markFailureRecorded();
    }

    if ($releasedTaskCreated) {
        $delivery->markReleasedTaskCreated();
    }

    expect($delivery->timeoutWasHandled())->toBe($handled);
})->with([
    'timed out and failed' => [true, true, false, true],
    'timed out and released' => [true, false, true, true],
    'timed out, nothing recorded' => [true, false, false, false],
    'failed without a handled timeout' => [false, true, false, false],
    'released without a handled timeout' => [false, false, true, false],
    'nothing happened' => [false, false, false, false],
]);

it('never treats a timeout as handled without a job', function () {
    $delivery = TaskDelivery::begin(Request::create('/handle-task', 'POST'));
    $delivery->markTimedOut();
    $delivery->markFailureRecorded();

    expect($delivery->timeoutWasHandled())->toBeFalse();
});
