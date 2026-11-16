<?php

// SBA Creators: plans and fees for creators selling courses and downloads.
// Fees are a share of the sale price and already include payment processing.
return [
    // Where links in creator and buyer emails point.
    'website_url' => env('CREATORS_WEBSITE_URL', 'https://sbaread-web-app.vercel.app'),

    'trial_days' => 30,
    'trial_plan' => 'pro',
    'plan_days' => 30,

    'plans' => [
        'starter' => ['name' => 'Starter', 'price_ngn' => 0, 'fee_ngn' => 0.04, 'fee_foreign' => 0.06],
        'pro' => ['name' => 'Pro', 'price_ngn' => 7500, 'fee_ngn' => 0.025, 'fee_foreign' => 0.045],
        'business' => ['name' => 'Business', 'price_ngn' => 15000, 'fee_ngn' => 0.015, 'fee_foreign' => 0.035],
    ],

    'min_price_ngn' => 100,
    'max_price_ngn' => 10000000,

    // What creators may upload, by lesson or file type.
    'uploads' => [
        'video' => ['types' => ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'], 'max_mb' => 2048],
        'pdf' => ['types' => ['pdf' => 'application/pdf'], 'max_mb' => 200],
        'file' => ['types' => [
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'epub' => 'application/epub+zip',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
        ], 'max_mb' => 2048],
        'cover' => ['types' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'], 'max_mb' => 5],
    ],
];
