<?php

namespace Fanswoo\CloudTasksGuard\Guards;

use Fanswoo\CloudTasksGuard\TaskDelivery;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksJob;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * Answers 200 when a handled job timeout ends a task handler request.
 *
 * The Cloud Tasks worker limits a job with `set_time_limit()`. When PHP's
 * "Maximum execution time of N seconds exceeded" fatal error fires, Laravel's
 * shutdown handler reports the error, and the worker's reportable callback
 * releases the job as a new task or fails it. The handler then renders the
 * error as a 500, so Cloud Tasks also delivers the original task again, beside
 * the released copy, and the job runs more often than its tries allow.
 *
 * This guard is a render callback for that fatal error. It answers 200 only
 * for a task handler request whose job timed out and was then fully handled:
 * the worker raised `JobTimedOut`, and either the job's failure was recorded
 * or a released copy of the job was created as a new task. In every other
 * case it renders nothing, and the 500 stays so that Cloud Tasks retries the
 * task instead of losing it.
 */
final class HandledTimeoutGuard
{
    /**
     * The message pattern of PHP's maximum execution time fatal error, as the
     * Cloud Tasks worker matches it.
     */
    public const string TIMEOUT_MESSAGE_PATTERN = 'Maximum execution time * exceeded';

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * Remember the delivered job when it starts processing.
     */
    public function trackJob(JobProcessing $event): void
    {
        if ($event->job instanceof CloudTasksJob) {
            $this->delivery()?->attach($event->job);
        }
    }

    /**
     * Record that the worker handled a timeout of the delivered job.
     */
    public function recordTimeout(JobTimedOut $event): void
    {
        $delivery = $this->delivery();

        if ($delivery?->isFor($event->job)) {
            $delivery->markTimedOut();
        }
    }

    /**
     * Record that the delivered job's failure was dispatched to every listener
     * registered before this one, including the failed job logger.
     */
    public function recordFailure(JobFailed $event): void
    {
        $delivery = $this->delivery();

        if ($delivery?->isFor($event->job)) {
            $delivery->markFailureRecorded();
        }
    }

    /**
     * Record that a task carrying the delivered job's payload was created,
     * which is how the worker releases a job.
     */
    public function recordCreatedTask(TaskCreated $event): void
    {
        $delivery = $this->delivery();
        $uuid = $delivery?->job()?->uuid();

        if ($delivery !== null && $uuid !== null && $uuid === self::payloadUuid($event->task)) {
            $delivery->markReleasedTaskCreated();
        }
    }

    /**
     * Render the response for a fatal error, or null to leave it to the handler.
     */
    public function render(FatalError $error, Request $request): ?Response
    {
        try {
            if (! $this->isEnabled() || ! self::isTimeout($error)) {
                return null;
            }

            if (! TaskDelivery::of($request)?->timeoutWasHandled()) {
                return null;
            }

            return new Response('', Response::HTTP_OK);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Determine whether the guard is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('cloud-tasks-guard.answer_handled_timeouts', true);
    }

    /**
     * Determine whether the fatal error is PHP's maximum execution time error.
     */
    public static function isTimeout(FatalError $error): bool
    {
        return fnmatch(self::TIMEOUT_MESSAGE_PATTERN, $error->getMessage());
    }

    /**
     * Get the job UUID from the payload of an HTTP or App Engine task.
     */
    private static function payloadUuid(Task $task): ?string
    {
        $body = $task->getHttpRequest()?->getBody() ?: $task->getAppEngineHttpRequest()?->getBody();

        if (! is_string($body) || $body === '') {
            return null;
        }

        $payload = json_decode($body, true);

        return is_array($payload) && is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null;
    }

    /**
     * Get the delivery record of the request that is being handled, if any.
     */
    private function delivery(): ?TaskDelivery
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? TaskDelivery::of($request) : null;
    }
}
