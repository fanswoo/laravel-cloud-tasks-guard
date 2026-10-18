<?php

namespace Fanswoo\CloudTasksGuard\Guards;

use Fanswoo\CloudTasksGuard\DeliveryCounters;
use Fanswoo\CloudTasksGuard\TaskDelivery;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;

/**
 * Counts deliveries that never got an answer as tries.
 *
 * Laravel keeps a Cloud Tasks job's attempt count in the task payload. When an
 * instance stops in the middle of a job, Cloud Tasks gets no answer and delivers
 * the same task again with the same payload, so the count does not move and a
 * job that keeps crashing is never failed by Laravel.
 *
 * On `JobProcessing`, which Laravel's worker raises before it checks whether the
 * job already exceeds its maximum attempts, this guard adds the number of earlier
 * unanswered deliveries (the retry count minus the execution count) to the
 * job's attempts. A job that crashed as many times as its tries is then failed
 * properly on the next delivery.
 *
 * A job released by the worker is a new task whose counters start at zero and
 * whose payload already carries the raised attempt count, so nothing is
 * counted twice.
 */
final class UnansweredDeliveryGuard
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * Handle the `JobProcessing` event.
     */
    public function handle(JobProcessing $event): void
    {
        if (! $event->job instanceof CloudTasksJob) {
            return;
        }

        $request = $this->currentRequest();
        $delivery = TaskDelivery::of($request);

        if ($request === null || $delivery === null || ! $delivery->attach($event->job)) {
            return;
        }

        if (! $this->isEnabled() || ! $delivery->markUnansweredDeliveriesCounted()) {
            return;
        }

        $unansweredDeliveries = DeliveryCounters::fromRequest($request)?->unansweredDeliveries() ?? 0;

        if ($unansweredDeliveries > 0) {
            $event->job->setAttempts($event->job->attempts() + $unansweredDeliveries);
        }
    }

    /**
     * Determine whether the guard is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('cloud-tasks-guard.count_unanswered_deliveries', true);
    }

    /**
     * Get the request that is being handled, if any.
     */
    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? $request : null;
    }
}
