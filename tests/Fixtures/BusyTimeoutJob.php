<?php

namespace Fanswoo\CloudTasksGuard\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * A job that keeps running past its 1-second timeout, for the end-to-end tests.
 *
 * It records each run and each failure in a log file in the given directory,
 * because it runs in the built-in web server, not in the test process.
 */
final class BusyTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1;

    public function __construct(
        public string $stateDirectory,
        public int $tries = 2,
    ) {}

    public function handle(): void
    {
        $this->record('run attempts='.$this->attempts());

        // On Linux, set_time_limit() counts the CPU time of the script, not the
        // time spent in sleep() or in system calls, so the job keeps the CPU busy.
        $giveUpAt = microtime(true) + 10;

        while (microtime(true) < $giveUpAt) {
            // Busy wait.
        }

        $this->record('completed');
    }

    public function failed(?Throwable $exception): void
    {
        $this->record('failed '.($exception === null ? 'without an exception' : $exception::class));
    }

    private function record(string $line): void
    {
        file_put_contents($this->stateDirectory.'/job.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
