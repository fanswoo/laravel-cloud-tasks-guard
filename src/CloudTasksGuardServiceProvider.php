<?php

namespace Fanswoo\CloudTasksGuard;

use Fanswoo\CloudTasksGuard\Guards\UnansweredDeliveryGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskIncoming;

/**
 * Registers the guards on top of the stackkit Cloud Tasks queue driver.
 */
final class CloudTasksGuardServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration and the guards.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloud-tasks-guard.php', 'cloud-tasks-guard');

        $this->app->singleton(UnansweredDeliveryGuard::class);
    }

    /**
     * Register the event listeners.
     */
    public function boot(Dispatcher $events): void
    {
        $this->publishes([
            __DIR__.'/../config/cloud-tasks-guard.php' => $this->app->configPath('cloud-tasks-guard.php'),
        ], ['cloud-tasks-guard', 'cloud-tasks-guard-config']);

        $events->listen(TaskIncoming::class, function (): void {
            TaskDelivery::begin($this->app->make('request'));
        });

        $events->listen(JobProcessing::class, [UnansweredDeliveryGuard::class, 'handle']);
    }
}
