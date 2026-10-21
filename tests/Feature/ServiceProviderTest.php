<?php

use Fanswoo\CloudTasksGuard\CloudTasksGuardServiceProvider;
use Fanswoo\CloudTasksGuard\Guards\HandledTimeoutGuard;
use Fanswoo\CloudTasksGuard\Guards\UnansweredDeliveryGuard;
use Fanswoo\CloudTasksGuard\StartedDeliveries;
use Illuminate\Support\ServiceProvider;

it('enables both guards by default', function () {
    expect(config('cloud-tasks-guard.count_unanswered_deliveries'))->toBeTrue()
        ->and(config('cloud-tasks-guard.answer_handled_timeouts'))->toBeTrue()
        ->and(app(UnansweredDeliveryGuard::class)->isEnabled())->toBeTrue()
        ->and(app(HandledTimeoutGuard::class)->isEnabled())->toBeTrue();
});

it('counts the started deliveries in the default cache store by default', function () {
    expect(config('cloud-tasks-guard.started_deliveries_store'))->toBeNull()
        ->and(app(StartedDeliveries::class)->storeName())->toBeNull();
});

it('publishes the configuration file', function () {
    $paths = ServiceProvider::pathsToPublish(CloudTasksGuardServiceProvider::class, 'cloud-tasks-guard-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/cloud-tasks-guard.php')
        ->and(array_values($paths)[0])->toBe(config_path('cloud-tasks-guard.php'));
});

it('is discovered by Laravel through composer.json', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['providers'])->toBe([CloudTasksGuardServiceProvider::class]);
});
