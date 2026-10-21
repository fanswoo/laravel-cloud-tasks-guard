<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * A cache factory with named stores that records which stores were requested.
 *
 * Like Laravel's cache manager, it throws for a store that is not defined.
 */
final class RecordingCacheFactory implements Factory
{
    /**
     * The store names that were requested, in order. Null stands for the default store.
     *
     * @var list<string|null>
     */
    public array $requestedStores = [];

    /**
     * @param  array<string, Repository>  $stores
     */
    public function __construct(
        private readonly array $stores,
        private readonly string $defaultStore,
    ) {}

    public function store($name = null): Repository
    {
        $this->requestedStores[] = $name;

        $name ??= $this->defaultStore;

        return $this->stores[$name] ?? throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
    }
}
