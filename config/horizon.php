<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain & Path
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    */
    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web', 'horizon.auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:default' => 60,
        'redis:voice'   => 60,
        'redis:audio'   => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming (minutes)
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs — these won't appear in the Horizon dashboard
    |--------------------------------------------------------------------------
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics Retention (hours)
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Defaults (inherited by environment overrides)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        // General-purpose jobs (emails, notifications, etc.)
        'supervisor-default' => [
            'connection'           => 'redis',
            'queue'                => ['default'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 4,
            'minProcesses'         => 1,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 2,
            'timeout'              => 60,
            'nice'                 => 0,
        ],

        // Voice cloning jobs — fast but can spike with many authors
        'supervisor-voice' => [
            'connection'           => 'redis',
            'queue'                => ['voice'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'time',
            'maxProcesses'         => 4,
            'minProcesses'         => 1,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 256,
            'tries'                => 3,
            'timeout'              => 120,
            'nice'                 => 0,
        ],

        // Book audio generation — long-running, keep concurrency low to avoid memory pressure
        'supervisor-audio' => [
            'connection'           => 'redis',
            'queue'                => ['audio'],
            'balance'              => 'simple',
            'maxProcesses'         => 2,
            'minProcesses'         => 1,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 512,
            'tries'                => 2,
            'timeout'              => 1800,
            'nice'                 => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-specific Overrides
    |--------------------------------------------------------------------------
    */
    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses'      => 4,
                'balanceMaxShift'   => 1,
                'balanceCooldown'   => 3,
            ],
            'supervisor-voice' => [
                'maxProcesses'      => 4,
                'balanceMaxShift'   => 1,
                'balanceCooldown'   => 3,
            ],
            'supervisor-audio' => [
                'maxProcesses'      => 2,
            ],
        ],

        'local' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-voice'   => ['maxProcesses' => 1],
            'supervisor-audio'   => ['maxProcesses' => 1],
        ],
    ],
];
