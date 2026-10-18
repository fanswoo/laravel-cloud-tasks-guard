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
    | When enabled, every delivery that did not end in a handled answer is
    | added to the job's attempts before Laravel checks its maximum tries.
    |
    */

    'count_unanswered_deliveries' => env('CLOUD_TASKS_GUARD_COUNT_UNANSWERED_DELIVERIES', true),

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
