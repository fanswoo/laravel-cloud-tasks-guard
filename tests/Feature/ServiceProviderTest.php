<?php

use Fanswoo\CloudTasksGuard\CloudTasksGuardServiceProvider;
use Fanswoo\CloudTasksGuard\Guards\UnansweredDeliveryGuard;
use Illuminate\Support\ServiceProvider;

it('enables the guard by default', function () {
    expect(config('cloud-tasks-guard.count_unanswered_deliveries'))->toBeTrue()
        ->and(app(UnansweredDeliveryGuard::class)->isEnabled())->toBeTrue();
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
