<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * A cache store that fails when it increments a value, as when the cache server cannot be reached.
 */
final class FailingCacheStore extends ArrayStore
{
    public function increment($key, $value = 1)
    {
        throw new RuntimeException('The cache store is unavailable.');
    }
}
