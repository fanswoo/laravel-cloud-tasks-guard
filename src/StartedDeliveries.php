<?php

namespace Fanswoo\CloudTasksGuard;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Counts, per task, the deliveries in which the job started.
 *
 * Cloud Tasks counts every delivery that ended in a 5xx as a retry, including
 * deliveries that never reached the job, so its counters alone cannot tell a
 * job that crashed from a request that failed before the job started. This
 * count, kept in Laravel's cache, records the deliveries that reached the job.
 *
 * The count lives in the cache store that the
 * `cloud-tasks-guard.started_deliveries_store` option names, or in the default
 * store when the option is null. Every instance that handles tasks must share
 * that store, and the store must keep its entries when an instance stops.
 */
final class StartedDeliveries
{
    /**
     * The prefix of every cache key that holds a count.
     */
    public const string KEY_PREFIX = 'cloud-tasks-guard:started-deliveries:';

    /**
     * How long a count is kept, in seconds: 32 days.
     *
     * Cloud Tasks deletes a task at the latest 31 days after it was added to its
     * queue ("Maximum task retention" in the Cloud Tasks quotas and limits). A
     * count is created by the first delivery that starts the job, which comes
     * after the task was added, so it outlives every delivery of its task.
     *
     * @see https://docs.cloud.google.com/tasks/docs/quotas
     */
    public const int TTL_SECONDS = 32 * 24 * 60 * 60;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    /**
     * Record that a delivery of the task started the job.
     *
     * Returns the number of earlier deliveries of the task that started the job,
     * or null when the store could not count them: the store does not exist,
     * does not support incrementing, or failed. A failing store never fails the
     * delivery.
     */
    public function record(TaskIdentity $task): ?int
    {
        try {
            $store = $this->cache->store($this->storeName());
            $key = self::key($task);

            // Create the count with its expiry first. Incrementing an existing
            // entry keeps its expiry, and the increment is atomic on the stores
            // that support it, such as Redis, Memcached, DynamoDB, and the database.
            $store->add($key, 0, self::TTL_SECONDS);
            $started = $store->increment($key);
        } catch (Throwable) {
            return null;
        }

        return is_int($started) && $started > 0 ? $started - 1 : null;
    }

    /**
     * The name of the cache store that holds the counts, or null for the default store.
     */
    public function storeName(): ?string
    {
        $name = $this->config->get('cloud-tasks-guard.started_deliveries_store');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The cache key of the task's count.
     *
     * The identifier is hashed so that the key stays short enough for every
     * store, whatever the length of the task name.
     */
    private static function key(TaskIdentity $task): string
    {
        return self::KEY_PREFIX.hash('sha256', $task->identifier());
    }
}
