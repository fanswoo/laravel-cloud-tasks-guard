<?php

namespace Fanswoo\CloudTasksGuard\Tests\Support;

use Throwable;

/**
 * Records what the in-process test jobs did, and scripts what they do next.
 */
final class JobRecorder
{
    public const string SUCCEED = 'succeed';

    public const string THROW = 'throw';

    public const string TIME_OUT = 'time-out';

    /**
     * The attempt count the job saw on each run.
     *
     * @var list<int>
     */
    public static array $runs = [];

    /**
     * The exceptions passed to the job's failed() method.
     *
     * @var list<Throwable|null>
     */
    public static array $failures = [];

    /**
     * The actions of the next runs, in order. A run without an action succeeds.
     *
     * @var list<string>
     */
    private static array $actions = [];

    public static function reset(): void
    {
        self::$runs = [];
        self::$failures = [];
        self::$actions = [];
    }

    public static function script(string ...$actions): void
    {
        self::$actions = array_values($actions);
    }

    public static function nextAction(): string
    {
        return array_shift(self::$actions) ?? self::SUCCEED;
    }
}
