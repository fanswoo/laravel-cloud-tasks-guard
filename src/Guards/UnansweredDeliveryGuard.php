<?php

namespace Fanswoo\CloudTasksGuard\Guards;

use Fanswoo\CloudTasksGuard\DeliveryCounters;
use Fanswoo\CloudTasksGuard\StartedDeliveries;
use Fanswoo\CloudTasksGuard\TaskDelivery;
use Fanswoo\CloudTasksGuard\TaskIdentity;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;

/**
 * Counts deliveries in which the job started and that never got an answer as tries.
 *
 * Laravel keeps a Cloud Tasks job's attempt count in the task payload. When an
 * instance stops in the middle of a job, Cloud Tasks gets no answer and delivers
 * the same task again with the same payload, so the count does not move and a
 * job that keeps crashing is never failed by Laravel.
 *
 * Cloud Tasks' retry count minus its execution count is the number of earlier
 * deliveries without a handled answer, but it also includes deliveries that
 * ended in a 5xx before the job started, such as a 503 while no instance was
 * ready. Those are not tries. So this guard records, per task, every delivery
 * that reaches `JobProcessing`, and counts as crashed only the earlier
 * deliveries that started the job and got no handled answer:
 *
 *     crashed = max(0, min(retry count - execution count, started before - execution count))
 *
 * On `JobProcessing`, which Laravel's worker raises before it checks whether the
 * job already exceeds its maximum attempts, this guard adds the crashed
 * deliveries to the job's attempts. A job that crashed as many times as its
 * tries is then failed properly on the next delivery.
 *
 * A job released by the worker is a new task with a new name, whose counters
 * and started deliveries start at zero and whose payload already carries the
 * raised attempt count, so nothing is counted twice.
 *
 * When the task cannot be identified, the counters are missing or malformed,
 * or the cache store fails, the guard counts nothing.
 */
final class UnansweredDeliveryGuard
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly StartedDeliveries $startedDeliveries,
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

        $crashedDeliveries = $this->recordStartAndCountCrashedDeliveries($request);

        if ($crashedDeliveries > 0) {
            $event->job->setAttempts($event->job->attempts() + $crashedDeliveries);
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
     * Record this delivery as one that started the job, and count the earlier
     * deliveries that started the job and did not end in a handled answer.
     */
    private function recordStartAndCountCrashedDeliveries(Request $request): int
    {
        $task = TaskIdentity::fromRequest($request);

        if ($task === null) {
            return 0;
        }

        $startedBefore = $this->startedDeliveries->record($task);
        $counters = DeliveryCounters::fromRequest($request);

        if ($startedBefore === null || $counters === null) {
            return 0;
        }

        return $counters->crashedDeliveries($startedBefore);
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
