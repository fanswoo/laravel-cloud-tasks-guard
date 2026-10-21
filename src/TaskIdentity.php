<?php

namespace Fanswoo\CloudTasksGuard;

use Illuminate\Http\Request;

/**
 * The identity of the task that Cloud Tasks delivers, from the names it sends
 * with every HTTP target request.
 *
 * - `X-CloudTasks-QueueName` is the name of the queue.
 * - `X-CloudTasks-TaskName` is the short name of the task, or a unique
 *   system-generated ID when the task was created without a name.
 *
 * A task name is unique within its queue, and every delivery of a task carries
 * the same name. A job that the worker releases becomes a new task with a new
 * name, so its deliveries have an identity of their own.
 *
 * The headers do not name the project or the location. The stackkit driver
 * starts every task name with a ULID, and Cloud Tasks generates a unique ID for
 * a task created without a name, so queues with the same name in other
 * projects or locations do not share an identity.
 *
 * @see https://docs.cloud.google.com/tasks/docs/creating-http-target-tasks
 * @see https://docs.cloud.google.com/tasks/docs/reference/rest/v2/projects.locations.queues.tasks
 */
final readonly class TaskIdentity
{
    public const string QUEUE_NAME_HEADER = 'X-CloudTasks-QueueName';

    public const string TASK_NAME_HEADER = 'X-CloudTasks-TaskName';

    /**
     * A queue ID: letters, numbers, or hyphens, at most 100 characters.
     */
    private const string QUEUE_NAME_PATTERN = '/^[A-Za-z0-9-]{1,100}$/D';

    /**
     * A task ID: letters, numbers, hyphens, or underscores, at most 500 characters.
     */
    private const string TASK_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,500}$/D';

    public function __construct(
        public string $queueName,
        public string $taskName,
    ) {}

    /**
     * Read the queue name and the task name from the request.
     *
     * Returns null when either header is missing or does not have the format
     * that Cloud Tasks allows for queue and task IDs, so that a request whose
     * task cannot be identified never adds attempts.
     */
    public static function fromRequest(Request $request): ?self
    {
        $queueName = self::parseName($request->headers->get(self::QUEUE_NAME_HEADER), self::QUEUE_NAME_PATTERN);
        $taskName = self::parseName($request->headers->get(self::TASK_NAME_HEADER), self::TASK_NAME_PATTERN);

        if ($queueName === null || $taskName === null) {
            return null;
        }

        return new self($queueName, $taskName);
    }

    /**
     * The identifier of the task: its queue name and its task name.
     *
     * A queue name never contains a slash, so the identifier is unambiguous.
     */
    public function identifier(): string
    {
        return $this->queueName.'/'.$this->taskName;
    }

    /**
     * Parse a name header value, accepting only the given format.
     */
    private static function parseName(?string $value, string $pattern): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
