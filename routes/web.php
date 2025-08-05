<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'SBA Reads API is running successfully!',
        'status' => 'healthy',
        'timestamp' => now()->toISOString()
    ]);
});

Route::get('/test-form', function () {
    return view('new');
});
