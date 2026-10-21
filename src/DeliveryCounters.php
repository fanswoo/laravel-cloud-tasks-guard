<?php

namespace Fanswoo\CloudTasksGuard;

use Illuminate\Http\Request;

/**
 * The delivery counters that Cloud Tasks sends with every HTTP target request.
 *
 * - `X-CloudTasks-TaskRetryCount` is the number of times the task has been
 *   retried. It includes attempts that failed with a 5xx status code or never
 *   reached the execution phase.
 * - `X-CloudTasks-TaskExecutionCount` is the number of times the handler sent
 *   a response. It does not include failures with a 5xx status code.
 *
 * Their difference is the number of earlier deliveries that did not end in a
 * handled answer. It also counts deliveries that ended in a 5xx before the job
 * started, so it is combined with the deliveries that started the job (see
 * StartedDeliveries) to count only the deliveries in which the job crashed.
 *
 * @see https://docs.cloud.google.com/tasks/docs/creating-http-target-tasks
 */
final readonly class DeliveryCounters
{
    public const string RETRY_COUNT_HEADER = 'X-CloudTasks-TaskRetryCount';

    public const string EXECUTION_COUNT_HEADER = 'X-CloudTasks-TaskExecutionCount';

    public function __construct(
        public int $retryCount,
        public int $executionCount,
    ) {}

    /**
     * Read both counters from the request.
     *
     * Returns null when either header is missing or is not a plain
     * non-negative integer, so that a malformed request never adds attempts.
     */
    public static function fromRequest(Request $request): ?self
    {
        $retryCount = self::parseCounter($request->headers->get(self::RETRY_COUNT_HEADER));
        $executionCount = self::parseCounter($request->headers->get(self::EXECUTION_COUNT_HEADER));

        if ($retryCount === null || $executionCount === null) {
            return null;
        }

        return new self($retryCount, $executionCount);
    }

    /**
     * The number of earlier deliveries that did not end in a handled answer.
     */
    public function unansweredDeliveries(): int
    {
        return max(0, $this->retryCount - $this->executionCount);
    }

    /**
     * The number of earlier deliveries that started the job and did not end in
     * a handled answer, given the number of earlier deliveries that started it.
     *
     * Each earlier delivery that started the job either sent a handled answer,
     * which the execution count includes, or did not. The started deliveries
     * minus the execution count is therefore the number of started deliveries
     * without a handled answer, as long as every handled answer came from a
     * delivery that started the job. A handled answer sent before the job
     * started, such as a 404 or a 422 from the task handler, lowers the result;
     * it never raises it. The result never exceeds the unanswered deliveries,
     * so a delivery that ended in a 5xx before the job started is never counted.
     */
    public function crashedDeliveries(int $startedDeliveries): int
    {
        return max(0, min($this->unansweredDeliveries(), $startedDeliveries - $this->executionCount));
    }

    /**
     * Parse a counter header value, accepting only plain decimal integers.
     *
     * At most 18 digits are accepted so the value always fits in an integer.
     */
    private static function parseCounter(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(0|[1-9][0-9]{0,17})$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
