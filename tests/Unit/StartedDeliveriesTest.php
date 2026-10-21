<?php

use Fanswoo\CloudTasksGuard\StartedDeliveries;
use Fanswoo\CloudTasksGuard\TaskIdentity;
use Fanswoo\CloudTasksGuard\Tests\Support\FailingCacheStore;
use Fanswoo\CloudTasksGuard\Tests\Support\RecordingCacheFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Carbon;

function startedDeliveries(Factory $cache, ?string $store = null): StartedDeliveries
{
    return new StartedDeliveries($cache, new Config([
        'cloud-tasks-guard' => ['started_deliveries_store' => $store],
    ]));
}

function arrayCacheFactory(): RecordingCacheFactory
{
    return new RecordingCacheFactory(['array' => new CacheRepository(new ArrayStore)], 'array');
}

afterEach(function () {
    Carbon::setTestNow();
});

it('returns the number of deliveries that started the job before this one', function () {
    $deliveries = startedDeliveries(arrayCacheFactory());
    $task = new TaskIdentity('guard-queue', 'task-1');

    expect($deliveries->record($task))->toBe(0)
        ->and($deliveries->record($task))->toBe(1)
        ->and($deliveries->record($task))->toBe(2);
});

it('counts every task on its own', function () {
    $deliveries = startedDeliveries(arrayCacheFactory());

    $deliveries->record(new TaskIdentity('guard-queue', 'task-1'));

    expect($deliveries->record(new TaskIdentity('guard-queue', 'task-2')))->toBe(0)
        ->and($deliveries->record(new TaskIdentity('other-queue', 'task-1')))->toBe(0)
        ->and($deliveries->record(new TaskIdentity('guard-queue', 'task-1')))->toBe(1);
});

it('keeps a count for longer than Cloud Tasks keeps a task', function () {
    $firstStart = Carbon::parse('2026-01-01 00:00:00');
    $deliveries = startedDeliveries(arrayCacheFactory());
    $task = new TaskIdentity('guard-queue', 'task-1');

    Carbon::setTestNow($firstStart);
    $deliveries->record($task);

    // Cloud Tasks deletes a task at the latest 31 days after it was added to its queue.
    Carbon::setTestNow($firstStart->copy()->addDays(31));

    expect($deliveries->record($task))->toBe(1);

    // Later deliveries do not extend the lifetime of the count.
    Carbon::setTestNow($firstStart->copy()->addSeconds(StartedDeliveries::TTL_SECONDS));

    expect($deliveries->record($task))->toBe(0)
        ->and(StartedDeliveries::TTL_SECONDS)->toBeGreaterThan(31 * 24 * 60 * 60);
});

it('uses the configured cache store, or the default store when none is configured', function (?string $configured, ?string $requested) {
    $cache = new RecordingCacheFactory([
        'array' => new CacheRepository(new ArrayStore),
        'started-deliveries' => new CacheRepository(new ArrayStore),
    ], 'array');

    $deliveries = startedDeliveries($cache, $configured);
    $deliveries->record(new TaskIdentity('guard-queue', 'task-1'));

    expect($deliveries->storeName())->toBe($requested)
        ->and($cache->requestedStores)->toBe([$requested]);
})->with([
    'no store configured' => [null, null],
    'an empty store name' => ['', null],
    'a named store' => ['started-deliveries', 'started-deliveries'],
]);

it('keeps the count only in the configured store', function () {
    $cache = new RecordingCacheFactory([
        'array' => new CacheRepository(new ArrayStore),
        'started-deliveries' => new CacheRepository(new ArrayStore),
    ], 'array');
    $task = new TaskIdentity('guard-queue', 'task-1');

    startedDeliveries($cache, 'started-deliveries')->record($task);

    expect(startedDeliveries($cache, 'started-deliveries')->record($task))->toBe(1)
        ->and(startedDeliveries($cache)->record($task))->toBe(0);
});

it('returns no count when the store cannot count', function (string $store) {
    $cache = new RecordingCacheFactory([
        'failing' => new CacheRepository(new FailingCacheStore),
        'null' => new CacheRepository(new NullStore),
    ], 'failing');

    expect(startedDeliveries($cache, $store)->record(new TaskIdentity('guard-queue', 'task-1')))->toBeNull();
})->with([
    'a store that is not defined' => 'missing',
    'a store that fails' => 'failing',
    'a store that does not keep values' => 'null',
]);
