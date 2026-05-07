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

        // Book audio coordinator — downloads PDF, dispatches chunk batch, finalises.
        // Low concurrency: these jobs are short but memory-heavy (PDF parsing).
        'supervisor-audio' => [
            'connection'           => 'redis',
            'queue'                => ['audio'],
            'balance'              => 'simple',
            'maxProcesses'         => 3,
            'minProcesses'         => 1,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 512,
            'tries'                => 2,
            'timeout'              => 300,
            'nice'                 => 0,
        ],

        // Audio chunk workers — one job per TTS chunk, run in parallel.
        // 5 workers = 5 simultaneous ElevenLabs calls (safe for Creator/Pro plan).
        // Raise maxProcesses to 10-20 when on Scale plan.
        'supervisor-audio-chunks' => [
            'connection'           => 'redis-chunks',
            'queue'                => ['audio-chunks'],
            'balance'              => 'auto',
            'autoScalingStrategy'  => 'size',
            'maxProcesses'         => 5,
            'minProcesses'         => 1,
            'maxTime'              => 0,
            'maxJobs'              => 0,
            'memory'               => 128,
            'tries'                => 3,
            'timeout'              => 120,
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
                'maxProcesses'      => 3,
            ],
            'supervisor-audio-chunks' => [
                'maxProcesses'      => 5,
                'balanceMaxShift'   => 2,
                'balanceCooldown'   => 3,
            ],
        ],

        'local' => [
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-voice'   => ['maxProcesses' => 1],
            'supervisor-audio'   => ['maxProcesses' => 1],
        ],
    ],
];
