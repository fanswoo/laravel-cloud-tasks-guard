<?php

namespace Fanswoo\CloudTasksGuard;

use Fanswoo\CloudTasksGuard\Guards\HandledTimeoutGuard;
use Fanswoo\CloudTasksGuard\Guards\UnansweredDeliveryGuard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\ServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskIncoming;
use Symfony\Component\ErrorHandler\Error\FatalError;

/**
 * Registers both guards on top of the stackkit Cloud Tasks queue driver.
 */
final class CloudTasksGuardServiceProvider extends ServiceProvider
{
    /**
     * Register the package configuration and the guards.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloud-tasks-guard.php', 'cloud-tasks-guard');

        $this->app->scoped(UnansweredDeliveryGuard::class);
        $this->app->scoped(HandledTimeoutGuard::class);
    }

    /**
     * Register the event listeners and the fatal error render callback.
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
        $events->listen(JobProcessing::class, [HandledTimeoutGuard::class, 'trackJob']);
        $events->listen(JobTimedOut::class, [HandledTimeoutGuard::class, 'recordTimeout']);
        $events->listen(TaskCreated::class, [HandledTimeoutGuard::class, 'recordCreatedTask']);

        // Listen once every provider has booted, so that this listener runs after
        // the stackkit listener that writes the failed job to the failed_jobs table.
        $this->app->booted(function () use ($events): void {
            $events->listen(JobFailed::class, [HandledTimeoutGuard::class, 'recordFailure']);
        });

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler, Container $container): void {
            if (! method_exists($handler, 'renderable')) {
                return;
            }

            $handler->renderable(
                fn (FatalError $error, mixed $request) => $request instanceof Request
                    ? $container->make(HandledTimeoutGuard::class)->render($error, $request)
                    : null
            );
        });
    }
}
