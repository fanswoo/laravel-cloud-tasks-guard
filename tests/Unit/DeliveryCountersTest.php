<?php

use Fanswoo\CloudTasksGuard\DeliveryCounters;
use Illuminate\Http\Request;

function requestWithCounters(?string $retryCount, ?string $executionCount): Request
{
    $request = Request::create('/handle-task', 'POST');

    if ($retryCount !== null) {
        $request->headers->set(DeliveryCounters::RETRY_COUNT_HEADER, $retryCount);
    }

    if ($executionCount !== null) {
        $request->headers->set(DeliveryCounters::EXECUTION_COUNT_HEADER, $executionCount);
    }

    return $request;
}

it('reads both counters from the request', function () {
    $counters = DeliveryCounters::fromRequest(requestWithCounters('4', '1'));

    expect($counters)->not->toBeNull()
        ->and($counters->retryCount)->toBe(4)
        ->and($counters->executionCount)->toBe(1)
        ->and($counters->unansweredDeliveries())->toBe(3);
});

it('reads header names case-insensitively and ignores surrounding spaces', function () {
    $request = Request::create('/handle-task', 'POST');
    $request->headers->set('x-cloudtasks-taskretrycount', ' 2 ');
    $request->headers->set('X-CLOUDTASKS-TASKEXECUTIONCOUNT', '0');

    expect(DeliveryCounters::fromRequest($request)?->unansweredDeliveries())->toBe(2);
});

it('returns no counters when a header is missing or not a plain integer', function (?string $retryCount, ?string $executionCount) {
    expect(DeliveryCounters::fromRequest(requestWithCounters($retryCount, $executionCount)))->toBeNull();
})->with([
    'both missing' => [null, null],
    'retry count missing' => [null, '0'],
    'execution count missing' => ['0', null],
    'empty' => ['', '0'],
    'word' => ['one', '0'],
    'negative' => ['-1', '0'],
    'decimal' => ['1.0', '0'],
    'exponent' => ['1e2', '0'],
    'hexadecimal' => ['0x1', '0'],
    'leading zero' => ['01', '0'],
    'plus sign' => ['+1', '0'],
    'too many digits' => ['1234567890123456789', '0'],
    'list of values' => ['1, 2', '0'],
]);

it('counts only the unanswered deliveries in which the job started', function (int $retryCount, int $executionCount, int $startedDeliveries, int $crashedDeliveries) {
    expect((new DeliveryCounters($retryCount, $executionCount))->crashedDeliveries($startedDeliveries))->toBe($crashedDeliveries);
})->with([
    'first delivery' => [0, 0, 0, 0],
    'failures before the job started' => [3, 0, 0, 0],
    'every delivery crashed' => [3, 0, 3, 3],
    'two failures before the job started, then two crashes' => [4, 0, 2, 2],
    'more started deliveries than unanswered ones' => [2, 0, 5, 2],
    'answers before the job started' => [2, 2, 0, 0],
    'an answer before the job started, then one crash' => [2, 1, 1, 0],
    'an answer before the job started, then two crashes' => [3, 1, 2, 1],
    'more executions than retries' => [1, 3, 4, 0],
    'more executions than started deliveries' => [3, 2, 1, 0],
]);

it('never reports a negative number of unanswered deliveries', function () {
    expect((new DeliveryCounters(retryCount: 1, executionCount: 3))->unansweredDeliveries())->toBe(0);
});

it('accepts the largest counter that fits in an integer', function () {
    expect(DeliveryCounters::fromRequest(requestWithCounters('999999999999999999', '0'))?->retryCount)
        ->toBe(999999999999999999);
});
