<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure the Ymir health endpoint. These settings let you
    | check that your application boots and passes its diagnostics.
    |
    */

    'health' => [

        /*
        |----------------------------------------------------------------------
        | Enabled
        |----------------------------------------------------------------------
        |
        | This option determines if the "/ymir/health" endpoint is registered.
        | Its action requires runtime health check metadata before running
        | diagnostics and returns 404 otherwise, or 500 if a listener throws.
        | Application routes and middleware retain control over requests.
        |
        */

        'enabled' => env('YMIR_HEALTH_ENABLED', true),

    ],

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
