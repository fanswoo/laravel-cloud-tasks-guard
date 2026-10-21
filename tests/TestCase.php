<?php

namespace Fanswoo\CloudTasksGuard\Tests;

use Fanswoo\CloudTasksGuard\CloudTasksGuardServiceProvider;
use Fanswoo\CloudTasksGuard\StartedDeliveries;
use Fanswoo\CloudTasksGuard\TaskIdentity;
use Fanswoo\CloudTasksGuard\Tests\Support\DispatchedTask;
use Fanswoo\CloudTasksGuard\Tests\Support\JobRecorder;
use Fanswoo\CloudTasksGuard\Tests\Support\TestEnvironment;
use Fanswoo\CloudTasksGuard\Tests\Support\TimeoutSimulator;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApiFake;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        CloudTasksApi::fake();
        JobRecorder::reset();
        TimeoutSimulator::reset();
    }

    protected function tearDown(): void
    {
        // The stackkit worker calls set_time_limit() for every job it processes.
        set_time_limit(0);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        // The same order as Composer's package discovery, which sorts packages by name.
        return [
            CloudTasksGuardServiceProvider::class,
            CloudTasksServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        TestEnvironment::configure($app['config']);

        // The task handler reports the exceptions that these tests provoke.
        $app['config']->set('logging.default', 'null');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Creates the failed_jobs table among Laravel's default tables.
        $this->loadLaravelMigrations();
    }

    /**
     * Dispatch a job to the Cloud Tasks fake and return the task it created.
     */
    public function dispatchToCloudTasks(object $job): DispatchedTask
    {
        $createdBefore = count($this->cloudTasks()->createdTasks);

        dispatch($job);

        $created = array_slice($this->cloudTasks()->createdTasks, $createdBefore);

        $this->assertCount(1, $created, 'Dispatching the job should create exactly one task.');

        return new DispatchedTask($created[0]['task']);
    }

    /**
     * Deliver the task to the task handler the way Cloud Tasks does.
     */
    public function deliver(DispatchedTask $task, int|string|null $retryCount = 0, int|string|null $executionCount = 0): TestResponse
    {
        return $this->deliverWithHeaders($task, $task->headers($retryCount, $executionCount));
    }

    /**
     * Deliver the task to the task handler with the given headers.
     *
     * @param  array<string, string>  $headers
     */
    public function deliverWithHeaders(DispatchedTask $task, array $headers): TestResponse
    {
        return $this->call(
            method: 'POST',
            uri: route('cloud-tasks.handle-task'),
            server: $this->transformHeadersToServerVars($headers),
            content: $task->body(),
        );
    }

    /**
     * Record deliveries of the task that started the job, as earlier deliveries would have.
     */
    public function recordStartedDeliveries(DispatchedTask|TaskIdentity $task, int $count): void
    {
        $identity = $task instanceof DispatchedTask ? $task->identity() : $task;

        for ($recorded = 0; $recorded < $count; $recorded++) {
            $this->assertNotNull(app(StartedDeliveries::class)->record($identity));
        }
    }

    /**
     * Get the copies of the task's job that were released as new tasks.
     *
     * @return list<DispatchedTask>
     */
    public function releasedCopiesOf(DispatchedTask $task): array
    {
        $copies = [];

        foreach ($this->cloudTasks()->createdTasks as $created) {
            $copy = new DispatchedTask($created['task']);

            if ($copy->task->getName() !== $task->task->getName() && $copy->uuid() === $task->uuid()) {
                $copies[] = $copy;
            }
        }

        return $copies;
    }

    public function cloudTasks(): CloudTasksApiFake
    {
        $fake = CloudTasksApi::getFacadeRoot();

        $this->assertInstanceOf(CloudTasksApiFake::class, $fake);

        return $fake;
    }
}
