<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Count Unanswered Deliveries As Tries
    |--------------------------------------------------------------------------
    |
    | When an instance stops in the middle of a job (out of memory, a crash, a
    | lost connection), Cloud Tasks gets no answer and delivers the same task
    | again with the same payload, so Laravel's attempt count does not move.
    | When enabled, every earlier delivery in which the job started and that
    | did not end in a handled answer is added to the job's attempts before
    | Laravel checks its maximum tries. A delivery that ended in a 5xx before
    | the job started is not counted.
    |
    */

    'count_unanswered_deliveries' => env('CLOUD_TASKS_GUARD_COUNT_UNANSWERED_DELIVERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Started Deliveries Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store in which the guard above counts, per task, the deliveries
    | in which the job started. Null uses the default cache store. Every
    | instance that handles tasks must share the store, and the store must
    | keep its entries when an instance stops, so use Redis, Memcached,
    | DynamoDB, or the database store, not "array" or a per-instance "file"
    | store. Each count expires 32 days after the job first started, which is
    | longer than Cloud Tasks keeps a task (31 days).
    |
    */

    'started_deliveries_store' => env('CLOUD_TASKS_GUARD_STARTED_DELIVERIES_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Answer Handled Timeouts With 200
    |--------------------------------------------------------------------------
    |
    | When a job exceeds its timeout, the Cloud Tasks worker releases the job
    | as a new task or fails it, but PHP still ends the request with a 500,
    | so Cloud Tasks delivers the original task again. When enabled, such a
    | request answers 200 once the release or the failure has been recorded.
    |
    */

    'answer_handled_timeouts' => env('CLOUD_TASKS_GUARD_ANSWER_HANDLED_TIMEOUTS', true),

];
