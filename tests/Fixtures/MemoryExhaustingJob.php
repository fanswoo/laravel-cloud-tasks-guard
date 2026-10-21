<?php

namespace Fanswoo\CloudTasksGuard\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * A job that exhausts its memory limit on every run, for the end-to-end tests.
 *
 * PHP ends the request with a fatal error that the stackkit worker does not
 * handle, so the delivery ends with a 500 after the job started, as when an
 * instance runs out of memory in the middle of a job. Cloud Tasks gets no
 * handled answer and delivers the same task again.
 *
 * It records each run and each failure in a log file in the given directory,
 * because it runs in the built-in web server, not in the test process.
 */
final class MemoryExhaustingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public string $stateDirectory,
        public int $tries = 2,
    ) {}

    public function handle(): void
    {
        $this->record('run attempts='.$this->attempts());

        ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));

        $blocks = [];

        while (count($blocks) < 1024) {
            $blocks[] = str_repeat('x', 1024 * 1024);
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
