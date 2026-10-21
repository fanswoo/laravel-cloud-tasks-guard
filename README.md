# Laravel Cloud Tasks Guard

[![Tests](https://github.com/fanswoo/laravel-cloud-tasks-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/fanswoo/laravel-cloud-tasks-guard/actions/workflows/tests.yml)

`fanswoo/laravel-cloud-tasks-guard` makes Laravel jobs on Google Cloud Tasks as reliable as jobs on Laravel's database queue. It works on top of [`stackkit/laravel-google-cloud-tasks-queue`](https://github.com/stackkit/laravel-google-cloud-tasks-queue) 6.x and closes two gaps in how that driver counts tries and answers timeouts.

- [The problem](#the-problem)
- [How the guards work](#how-the-guards-work)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Required Cloud Tasks queue settings](#required-cloud-tasks-queue-settings)
- [Limits](#limits)
- [Testing](#testing)
- [License](#license)

## The problem

The stackkit driver keeps a job's attempt count in the task payload (`internal.attempts`). Cloud Tasks delivers a task to the task handler, and a job that fails is released as a **new task** whose payload carries the raised count. This works while every delivery ends in an answer. Two situations break it.

**1. A delivery that never got an answer is not counted as a try.** When an instance stops in the middle of a job (it runs out of memory, crashes, or loses its connection), Cloud Tasks receives no answer and delivers the same task again, with the same payload. The attempt count does not move. A job that keeps crashing is never failed by Laravel: its `failed()` method never runs and no `failed_jobs` row is written. Cloud Tasks drops the task when its own retry limits run out, and the job disappears without a trace.

**2. A handled timeout still answers 500.** The stackkit worker passes the job's timeout to `set_time_limit()`. When PHP's "Maximum execution time of N seconds exceeded" fatal error fires, the worker handles it in a reportable callback: it releases the job as a new task, or fails it. But the request still ends with a 500, so Cloud Tasks also delivers the original task again, beside the released copy. The job runs more often than its tries allow.

## How the guards work

The package registers two guards. Each one has its own switch, and both are on by default.

Both guards act only on the job of a **task handler request**: a request in which the stackkit task handler raised its `TaskIncoming` event. The package marks that request with a small delivery record, so jobs processed anywhere else (a queue worker, the Cloud Run job command, the `sync` driver) are never changed.

### Guard 1: count unanswered deliveries as tries

Cloud Tasks sends these headers with every delivery to an HTTP target ([Google's documentation](https://docs.cloud.google.com/tasks/docs/creating-http-target-tasks)):

| Header | Meaning |
| --- | --- |
| `X-CloudTasks-QueueName` | "The name of the queue." |
| `X-CloudTasks-TaskName` | "The "short" name of the task, or, if no name was specified at creation, a unique system-generated id." |
| `X-CloudTasks-TaskRetryCount` | "The number of times this task has been retried. For the first attempt, this value is `0`. This number includes attempts where the task failed due to 5XX error codes and never reached the execution phase." |
| `X-CloudTasks-TaskExecutionCount` | "The total number of times that the task has received a response from the handler. [...] This number does not include failures due to 5XX error codes." |

The difference of the two counters is the number of earlier deliveries that did not end in a handled answer: deliveries that ended in a 5xx, or that got no response at all. It also includes deliveries that failed **before the job started**: a 503 during a cold start, a 429 or a 500 from the Cloud Run front end, an instance that was shutting down, or a 500 from the application before the task handler ran the job. Those are not tries, and the counters alone cannot tell them from a crash in the middle of a job.

The guard therefore also counts, per task, the deliveries in which the job started. Every delivery that reaches Laravel's `JobProcessing` event adds one to a count in Laravel's cache, keyed by the queue name and the task name. A delivery that started the job either sent a handled answer, which the execution count includes, or it crashed:

```text
unanswered deliveries = X-CloudTasks-TaskRetryCount - X-CloudTasks-TaskExecutionCount
started deliveries    = earlier deliveries of the task that reached JobProcessing
crashed deliveries    = max(0, min(unanswered deliveries, started deliveries - X-CloudTasks-TaskExecutionCount))
```

`UnansweredDeliveryGuard` listens to Laravel's `JobProcessing` event, records the delivery as started, and adds the crashed deliveries to the job's attempts with `setAttempts()`. Laravel's worker raises `JobProcessing` in `Worker::process()` **before** it calls `markJobAsFailedIfAlreadyExceedsMaxAttempts()`, so a job that crashed as many times as its tries is failed properly on the next delivery: its `failed()` method runs, and the stackkit `JobFailed` listener writes the `failed_jobs` row.

A job with `$tries = 3` that crashes on every run:

| Delivery | Task | Retry count | Execution count | Started before | Attempts without the guard | Attempts with the guard | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | A | 0 | 0 | 0 | 1 | 1 | crash, no answer |
| 2 | A | 1 | 0 | 1 | 1 | 2 | crash, no answer |
| 3 | A | 2 | 0 | 2 | 1 | 3 | crash, no answer |
| 4 | A | 3 | 0 | 3 | 1 | 4 | failed with `MaxAttemptsExceededException` |

**Deliveries that failed before the job started are not counted.** The same job, when three of its deliveries end in a 503 before the job starts:

| Delivery | Task | Retry count | Execution count | Started before | Attempts with the guard | Result |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | A | 0 | 0 | | | 503 before the job started |
| 2 | A | 1 | 0 | | | 503 before the job started |
| 3 | A | 2 | 0 | 0 | 1 | crash, no answer |
| 4 | A | 3 | 0 | | | 503 before the job started |
| 5 | A | 4 | 0 | 1 | 2 | crash, no answer |
| 6 | A | 5 | 0 | 2 | 3 | crash, no answer |
| 7 | A | 6 | 0 | 3 | 4 | failed with `MaxAttemptsExceededException` |

Counting the unanswered deliveries alone would have failed this job at delivery 5, after a single run.

**Released jobs are not counted twice.** A released job is a new task with a new name. Its counters and its started deliveries start at 0, and its payload already carries the raised attempt count:

| Delivery | Task | Retry count | Execution count | Started before | Attempts with the guard | Result |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | A | 0 | 0 | 0 | 1 | throws, released as task B with attempts 1, answers 200 |
| 2 | B | 0 | 0 | 0 | 2 | crash, no answer |
| 3 | B | 1 | 0 | 1 | 3 | throws on the third try, failed |

**When in doubt, the guard counts nothing.** It adds attempts only when all of these are true:

- both counters are present and both are plain non-negative integers: a missing header, a word, a negative or decimal number, a value with a leading zero, a value too large for an integer, or an execution count above the retry count adds nothing;
- the queue name and the task name are present and have the format that Cloud Tasks allows ([`Task.name`](https://docs.cloud.google.com/tasks/docs/reference/rest/v2/projects.locations.queues.tasks)): a queue ID of at most 100 letters, numbers, or hyphens, and a task ID of at most 500 letters, numbers, hyphens, or underscores; and
- the cache store counted the delivery: a store that is not defined, that cannot be reached, or that cannot increment a value adds nothing, and the delivery goes on as usual.

### Guard 2: answer handled timeouts with 200

This is what happens when a job exceeds its timeout in the task handler:

1. The stackkit worker has called `set_time_limit()` with the job's timeout, and PHP raises the fatal error "Maximum execution time of N seconds exceeded".
2. Laravel's shutdown function (`HandleExceptions::handleShutdown()`) turns it into a `Symfony\Component\ErrorHandler\Error\FatalError` and reports it to the exception handler.
3. The stackkit worker's reportable callback releases the job as a new task, or fails it (on its last try, or when it should fail on timeout).
4. Laravel renders the error and sends the response. Without this package, that response is a 500, and Cloud Tasks delivers the original task again.

`HandledTimeoutGuard` is a render callback for `FatalError`, registered with the exception handler's documented `renderable()` method. In step 4 it answers **200 with an empty body** when all of these are true:

- the guard is enabled;
- the error message matches `Maximum execution time * exceeded` (the same pattern the stackkit worker uses);
- the request is a task handler request, and its job is the job that timed out;
- the worker raised `JobTimedOut` for that job, which it does only after its failure checks completed without an exception; and
- the job's failure was dispatched to every `JobFailed` listener up to and including the stackkit listener that writes `failed_jobs`, **or** a task carrying the job's UUID (the released copy) was created.

In every other case the guard renders nothing and the 500 stays, so that Cloud Tasks retries the task instead of losing it. That includes a release that could not be created (for example when the Cloud Tasks API is unavailable), a fatal error that the worker did not handle, any other fatal error such as an exhausted memory limit, and fatal errors outside the task handler.

The guard sends 200 rather than 204 because any 2xx status completes a Cloud Tasks task, and 200 is the status the task handler sends for every job it processed.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- `stackkit/laravel-google-cloud-tasks-queue` 6.x, with tasks delivered to the HTTP task handler (an HTTP target such as Cloud Run)
- For Guard 1, a cache store that every instance shares, such as Redis, Memcached, DynamoDB, or the database store (see [Configuration](#configuration))

## Installation

```bash
composer require fanswoo/laravel-cloud-tasks-guard
```

Laravel discovers the service provider automatically. Both guards are active as soon as the package is installed. Guard 1 keeps its count of started deliveries in the default cache store, which must be shared by every instance; see [The started deliveries store](#the-started-deliveries-store).

## Configuration

Publish the configuration file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=cloud-tasks-guard-config
```

```php
// config/cloud-tasks-guard.php
return [
    'count_unanswered_deliveries' => env('CLOUD_TASKS_GUARD_COUNT_UNANSWERED_DELIVERIES', true),
    'started_deliveries_store' => env('CLOUD_TASKS_GUARD_STARTED_DELIVERIES_STORE'),
    'answer_handled_timeouts' => env('CLOUD_TASKS_GUARD_ANSWER_HANDLED_TIMEOUTS', true),
];
```

| Key | Environment variable | Default | Effect |
| --- | --- | --- | --- |
| `count_unanswered_deliveries` | `CLOUD_TASKS_GUARD_COUNT_UNANSWERED_DELIVERIES` | `true` | Guard 1: add the earlier deliveries that started the job and got no answer to the job's attempts. |
| `started_deliveries_store` | `CLOUD_TASKS_GUARD_STARTED_DELIVERIES_STORE` | `null` | Guard 1: the cache store that counts the deliveries that started the job. `null` uses the default cache store. |
| `answer_handled_timeouts` | `CLOUD_TASKS_GUARD_ANSWER_HANDLED_TIMEOUTS` | `true` | Guard 2: answer 200 once a timed-out job was released or failed. |

### The started deliveries store

Guard 1 keeps its count of started deliveries in Laravel's cache. **Use a store that every instance shares and that keeps its entries when an instance stops**: Redis, Memcached, DynamoDB, or the database store. An instance that crashes is replaced, and the next delivery usually reaches another instance. With the `array` store, or a `file` store on the instance's own disk, that delivery finds no started deliveries, and the guard counts nothing. Set `started_deliveries_store` when the default cache store is not shared.

Each task has one small entry under the key `cloud-tasks-guard:started-deliveries:` followed by a SHA-256 hash of the queue name and the task name (after the store's own prefix). The guard creates it with `add()` and raises it with `increment()`, which is atomic on Redis, Memcached, DynamoDB, and the database store. The entry is not removed when the job finishes. It expires 32 days after the first delivery that started the job, which is longer than Cloud Tasks keeps a task: the "Maximum task retention" in [Cloud Tasks quotas and limits](https://docs.cloud.google.com/tasks/docs/quotas) is 31 days.

While Guard 1 is disabled, it records nothing. After you enable it again, the deliveries that started a job while the guard was disabled are missing from the count, so they are not counted as tries.

### Tries

Give every job a finite number of tries (`$tries`, or `maxTries` through `CloudTasksQueue::configureWorkerOptionsUsing()`). The stackkit driver uses one try by default. A job with unlimited tries (`$tries = 0`) is never failed by its attempt count, with or without the guard.

## Required Cloud Tasks queue settings

The guard moves the decision to fail a job into Laravel, so **Cloud Tasks must keep delivering a task until Laravel has decided**. Its retries must outlast the job's tries. For example:

```bash
gcloud tasks queues update QUEUE_ID \
    --location=LOCATION \
    --max-attempts=-1 \
    --max-retry-duration=86400s \
    --min-backoff=10s \
    --max-backoff=300s \
    --max-doublings=4 \
    --log-sampling-ratio=1.0
```

The flags are described in [Configure Cloud Tasks queues](https://docs.cloud.google.com/tasks/docs/configuring-queues) and in `gcloud tasks queues update --help`.

| Setting | Why it matters |
| --- | --- |
| `--max-attempts=-1` | Unlimited attempts. Cloud Tasks must never delete a task before Laravel has failed it; a task deleted by Cloud Tasks gets no `failed()` call and no `failed_jobs` row. Google's documentation notes that the maximum retry duration "still applies even if `MAX_ATTEMPTS` is reached or set to `-1`", so this setting is safe only together with the next one. |
| `--max-retry-duration=86400s` | The upper bound on retries, measured from the first attempt. 24 hours outlasts the tries of most jobs. Choose a value longer than the time that all tries of your slowest job can take, including backoff. |
| `--min-backoff=10s` | Waits at least 10 seconds before Cloud Tasks delivers the same task again, so that a crashing job does not hit a failing instance in a tight loop, and a replacement instance has time to start. |
| `--max-backoff=300s` and `--max-doublings=4` | The interval doubles four times (10, 20, 40, 80, 160 seconds) and then stays at 300 seconds, so a task that keeps failing gets fewer than 300 attempts in 24 hours. |
| `--log-sampling-ratio=1.0` | Writes every attempt to Cloud Logging. The default, `0.0`, logs nothing, so failed attempts are invisible. |

These settings apply only to deliveries of the same task: unanswered deliveries, 5xx answers, and the 4xx rejections described under [Limits](#limits). A job that throws is released as a new task, and Laravel's own backoff (`$backoff`) delays it.

Add an alert on the `cloudtasks.googleapis.com/queue/task_attempt_count` metric (resource `cloud_tasks_queue`, "Count of task attempts broken down by response code"), for example when the count of attempts whose `response_code` label is not `ok` stays above zero:

```text
resource.type = "cloud_tasks_queue"
AND metric.type = "cloudtasks.googleapis.com/queue/task_attempt_count"
AND metric.labels.response_code != "ok"
```

Keep the job's timeout shorter than the task's dispatch deadline (10 minutes by default for HTTP targets, at most 30 minutes; the stackkit connection option is `dispatch_deadline`) and shorter than the request timeout of your service. PHP's time limit must fire first, or the platform ends the request, and the delivery becomes an unanswered delivery instead of a handled timeout.

## Limits

- **A task rejected before its job runs is retried by Cloud Tasks until the retry duration ends.** The task handler checks that the task exists before it runs the job. When that check fails, for example because the service account lacks the permission to read tasks, the stackkit handler reports the error and answers 404. A 4xx answer counts as an execution, so Guard 1 counts nothing, and Laravel never sees the job. Cloud Tasks retries the task until `--max-retry-duration` ends and then deletes it without a `failed_jobs` row. An invalid payload (422) behaves the same way. The alert above is how you find these tasks.
- **The count of started deliveries lives in the cache.** A store that loses entries undercounts: an eviction under memory pressure, a flush, a restart without persistence, or a store that each instance keeps for itself. The job then runs more often than its tries, but it is never failed early. A store that is not defined or cannot be reached counts nothing. The guard does not report that error, so that a cache outage never fails a delivery; check the store name when you set it.
- **A 4xx answer before the job runs can hide a crash.** The 404 and 422 answers described above count as executions, although the job did not start. Each one lowers the crashed deliveries by one, so the job can run more often than its tries. It is never failed early.
- **What PHP's time limit counts depends on the PHP build.** A thread-safe (ZTS) build on Linux, such as FrankenPHP's, uses Zend max execution timers by default, which count wall-clock time: a job that waits on I/O is ended at its timeout like any other, and Guard 2 answers that request. A non-thread-safe (NTS) build, such as PHP-FPM or the usual CLI, counts only the time that PHP itself runs: `set_time_limit()` does not count time spent in `sleep()`, in system calls, in database queries, or in HTTP requests. There, a job that waits on I/O is ended by the dispatch deadline or by your platform's request timeout instead. That delivery gets no handled answer, so Guard 1 counts it as a try on the next delivery. `php -r 'echo PHP_ZTS;'` prints `1` on a thread-safe build.
- **Other fatal errors still end with 500.** When a job exhausts its memory limit, the stackkit worker neither releases nor fails it, and the 500 stays. Cloud Tasks delivers the same task again, and Guard 1 counts the failed delivery as a try.
- **Only HTTP deliveries are covered.** App Engine targets send `X-AppEngine-TaskRetryCount` and `X-AppEngine-TaskExecutionCount`, whose meaning differs, and Guard 1 ignores them. Jobs that Cloud Run jobs process through `cloud-tasks:work-job` are not HTTP requests, so neither guard applies.
- **Jobs with `retryUntil()` follow Laravel's rules.** Laravel ignores the attempt count while `retryUntil()` is in the future, so a crashing job with a retry deadline keeps running until the deadline passes. The next delivery after the deadline fails it.
- **Your own exception rendering runs first.** Laravel uses the first render callback that returns a response. A callback in your application that returns a response for every `Throwable` or `FatalError` takes precedence over Guard 2; make it return `null` for the task handler route. A `respond()` callback in your application also receives the 200 response and can change it.
- **Not tested under Laravel Octane.** The guards keep their state on the request, but the end-to-end tests cover a fresh application per request, as with PHP-FPM.

## Testing

```bash
composer test
```

The suite has three parts:

- **Unit** tests for the header parsing, the delivery record, and the count of started deliveries.
- **Feature** tests that deliver tasks to the task handler in the test process with Orchestra Testbench and `CloudTasksApi::fake()`. A real fatal error cannot be raised in a test process, so the timeout tests reproduce the shutdown sequence (report, then render) inside the running job.
- **End-to-end** tests that serve a Testbench application with this package, the stackkit driver, and `CloudTasksApi::fake()` through PHP's built-in web server (`php -S`). They deliver a task whose job keeps the CPU busy past a 1-second timeout, and assert that the request answers 200 and that the job is released or failed exactly once. With the guard disabled, the same delivery ends with a 500 beside a released copy. They also deliver a task whose job exhausts its memory limit, so that every delivery ends with a 500 after the job started, and assert that the job is failed once those deliveries use up its tries, and that deliveries that failed before the job started are not counted. The served application keeps the started deliveries in a file cache store in the test's state directory, which outlives each request as a shared cache outlives an instance.

Run the tests without Xdebug (`XDEBUG_MODE=off`). With Xdebug active, `set_time_limit()` returns `false`, and the stackkit worker's `Safe\set_time_limit()` call throws before the job runs.

Check the code style with Laravel Pint:

```bash
composer test:lint
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License. See [LICENSE](LICENSE).
