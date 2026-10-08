<?php

use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static fn () => response()->json([
    'status' => 'ok',
    'service' => 'nexora-distribution-api',
]));