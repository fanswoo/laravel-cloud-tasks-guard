<?php

namespace Fanswoo\CloudTasksGuard\Tests\Fixtures;

use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

/**
 * A job that records its attempts and failures, and acts as JobRecorder scripts it.
 *
 * It sets no timeout on purpose: the stackkit worker passes the job's timeout to
 * set_time_limit(), which would also limit the test process itself.
 */
final class RecordedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $tries = 3,
    ) {}

    public function handle(): void
    {
        JobRecorder::$runs[] = $this->attempts();

        match (JobRecorder::nextAction()) {
            JobRecorder::THROW => throw new RuntimeException('The job failed.'),
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        JobRecorder::$failures[] = $exception;
    }
}
