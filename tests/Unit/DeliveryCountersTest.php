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

it('never reports a negative number of unanswered deliveries', function () {
    expect((new DeliveryCounters(retryCount: 1, executionCount: 3))->unansweredDeliveries())->toBe(0);
});

it('accepts the largest counter that fits in an integer', function () {
    expect(DeliveryCounters::fromRequest(requestWithCounters('999999999999999999', '0'))?->retryCount)
        ->toBe(999999999999999999);
});
