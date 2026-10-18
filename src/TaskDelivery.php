<?php

namespace Fanswoo\CloudTasksGuard;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;

/**
 * The record of one Cloud Tasks delivery that the task handler is processing.
 *
 * A delivery begins when the stackkit task handler raises its `TaskIncoming`
 * event, and the record lives in the attributes of that request. Its presence
 * is what marks a request as a task handler request, and because it belongs to
 * the request, it never leaks into another request on a long-running server.
 */
final class TaskDelivery
{
    public const string REQUEST_ATTRIBUTE = 'cloud_tasks_guard.delivery';

    private ?CloudTasksJob $job = null;

    private ?string $jobUuid = null;

    private bool $unansweredDeliveriesCounted = false;

    private bool $timedOut = false;

    private bool $failureRecorded = false;

    private bool $releasedTaskCreated = false;

    /**
     * Start a delivery record on the given request.
     */
    public static function begin(Request $request): self
    {
        $delivery = new self;

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $delivery);

        return $delivery;
    }

    /**
     * Get the delivery record of the given request, if it is a task handler request.
     */
    public static function of(?Request $request): ?self
    {
        $delivery = $request?->attributes->get(self::REQUEST_ATTRIBUTE);

        return $delivery instanceof self ? $delivery : null;
    }

    /**
     * Attach the job that this delivery processes.
     *
     * The first Cloud Tasks job processed in the request is the delivered job.
     * Returns false when the given job is not that job.
     */
    public function attach(CloudTasksJob $job): bool
    {
        $this->job ??= $job;

        return $this->job === $job;
    }

    /**
     * Get the delivered job, once it has been attached.
     */
    public function job(): ?CloudTasksJob
    {
        return $this->job;
    }

    /**
     * Get the UUID of the delivered job, once it has been attached.
     */
    public function jobUuid(): ?string
    {
        return $this->jobUuid ??= $this->job?->uuid();
    }

    /**
     * Determine whether the given job is the delivered job.
     */
    public function isFor(Job $job): bool
    {
        return $this->job !== null && $this->job === $job;
    }

    /**
     * Mark the unanswered deliveries as counted.
     *
     * Returns false when they were already counted, so they are never added twice.
     */
    public function markUnansweredDeliveriesCounted(): bool
    {
        if ($this->unansweredDeliveriesCounted) {
            return false;
        }

        return $this->unansweredDeliveriesCounted = true;
    }

    /**
     * Record that the worker handled a timeout of the delivered job.
     */
    public function markTimedOut(): void
    {
        $this->timedOut = true;
    }

    /**
     * Record that the failure of the delivered job was fully dispatched.
     */
    public function markFailureRecorded(): void
    {
        $this->failureRecorded = true;
    }

    /**
     * Record that a released copy of the delivered job was created as a new task.
     */
    public function markReleasedTaskCreated(): void
    {
        $this->releasedTaskCreated = true;
    }

    public function hasTimedOut(): bool
    {
        return $this->timedOut;
    }

    public function hasRecordedFailure(): bool
    {
        return $this->failureRecorded;
    }

    public function hasCreatedReleasedTask(): bool
    {
        return $this->releasedTaskCreated;
    }

    /**
     * Determine whether the timeout of the delivered job has been fully handled.
     *
     * The worker raised `JobTimedOut` (so its failure checks completed without an
     * exception), and either the job's failure was recorded or a released copy
     * of the job exists as a new task.
     */
    public function timeoutWasHandled(): bool
    {
        return $this->job !== null
            && $this->timedOut
            && ($this->failureRecorded || $this->releasedTaskCreated);
    }
}
