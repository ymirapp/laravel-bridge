<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logging Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure the logging settings for the Ymir Laravel Bridge.
    | These settings help you control how information is logged when your
    | application is running on the Ymir platform.
    |
    */

    'logging' => [

        /*
        |----------------------------------------------------------------------
        | Request Context
        |----------------------------------------------------------------------
        |
        | This option determines if the unique AWS request ID should be added
        | to the log context. When enabled, you can easily trace all log
        | entries associated with a specific request in CloudWatch.
        |
        */

        'request_context' => env('YMIR_LOG_REQUEST_CONTEXT', false),

    ],

];
