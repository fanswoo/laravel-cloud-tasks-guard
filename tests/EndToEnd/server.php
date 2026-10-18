<?php

/*
 * The front controller that the end-to-end tests serve with PHP's built-in web
 * server: a Testbench application with the stackkit queue driver, this package
 * and CloudTasksApi::fake(). Unlike an in-process test, a job that exceeds its
 * timeout here ends the request with a real fatal error, which Laravel handles
 * in its shutdown function.
 *
 * The test process passes these environment variables:
 *
 * - CLOUD_TASKS_GUARD_E2E_STATE: a directory where this application records the
 *   tasks it creates, its log, and its failed jobs, for the test to read.
 * - CLOUD_TASKS_GUARD_E2E_ANSWER_HANDLED_TIMEOUTS: "false" disables that guard.
 */

use Fanswoo\CloudTasksGuard\CloudTasksGuardServiceProvider;
use Fanswoo\CloudTasksGuard\Tests\Support\TestEnvironment;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Stackkit\LaravelGoogleCloudTasksQueue\IncomingTask;

require __DIR__.'/../../vendor/autoload.php';

$stateDirectory = (string) getenv('CLOUD_TASKS_GUARD_E2E_STATE');

$app = Application::create(options: [
    'extra' => [
        'providers' => [
            CloudTasksGuardServiceProvider::class,
            CloudTasksServiceProvider::class,
        ],
        'dont-discover' => ['*'],
        'env' => [
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_KEY=base64:'.base64_encode(str_repeat('k', 32)),
        ],
    ],
]);

TestEnvironment::configure($app['config']);

$app['config']->set('queue.failed', [
    'driver' => 'file',
    'path' => $stateDirectory.'/failed-jobs.json',
]);
$app['config']->set('logging.default', 'single');
$app['config']->set('logging.channels.single.path', $stateDirectory.'/laravel.log');
$app['config']->set(
    'cloud-tasks-guard.answer_handled_timeouts',
    getenv('CLOUD_TASKS_GUARD_E2E_ANSWER_HANDLED_TIMEOUTS') !== 'false',
);

$app['events']->listen(TaskCreated::class, function (TaskCreated $event) use ($stateDirectory): void {
    file_put_contents(
        $stateDirectory.'/created-tasks.log',
        $event->task->getHttpRequest()?->getBody().PHP_EOL,
        FILE_APPEND | LOCK_EX,
    );
});

$request = Request::capture();
$app->instance('request', $request);

CloudTasksApi::fake();

// The task handler checks that the delivered task exists, so the fake must know it.
try {
    $taskName = IncomingTask::fromJson($request->getContent())->fullyQualifiedTaskName();

    CloudTasksApi::createTask('', (new Task)->setName($taskName));
} catch (Throwable) {
    // Not a task delivery; the task handler rejects it.
}

$kernel = $app->make(Kernel::class);

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
