<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'HP Enterprise Brain API is running.',
        'api' => '/api/v1',
        'health' => '/health',
    ]);
});