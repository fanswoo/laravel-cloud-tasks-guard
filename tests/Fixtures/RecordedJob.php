<?php

namespace Fanswoo\CloudTasksGuard\Tests\Fixtures;

use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Fanswoo\CloudTasksGuard\Tests\Support\TimeoutSimulator;
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
        public string $fatalErrorMessage = TimeoutSimulator::TIMEOUT_MESSAGE,
    ) {}

    public function handle(): void
    {
        JobRecorder::$runs[] = $this->attempts();

        match (JobRecorder::nextAction()) {
            JobRecorder::THROW => throw new RuntimeException('The job failed.'),
            JobRecorder::TIME_OUT => TimeoutSimulator::shutDownWith($this->fatalErrorMessage),
            JobRecorder::DISPATCH_THEN_TIME_OUT => $this->dispatchAnotherJobThenTimeOut(),
            default => null,
        };
    }

    private function dispatchAnotherJobThenTimeOut(): void
    {
        dispatch(new self(tries: 1));

        TimeoutSimulator::shutDownWith($this->fatalErrorMessage);
    }

    public function failed(?Throwable $exception): void
    {
        JobRecorder::$failures[] = $exception;
    }
}
