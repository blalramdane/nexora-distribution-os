<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\SalesController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\TripLoadController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static fn () => response()->json([
    'status' => 'ok',
    'service' => 'nexora-distribution-api',
]));

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum','tenant'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/products', [CatalogController::class, 'products']);
        Route::post('/products', [CatalogController::class, 'storeProduct']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/locations', [LocationController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::post('/sales', [SalesController::class, 'store']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/returns/sales', [ReturnController::class, 'sales']);
    Route::post('/returns/purchases', [ReturnController::class, 'purchases']);
    Route::post('/trip-loads', [TripLoadController::class, 'store']);
    });
});