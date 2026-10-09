<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\SalesController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\FinanceReferenceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\TripLoadController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripSettlementController;
use App\Http\Controllers\Api\V1\FieldController;
use App\Http\Controllers\Api\V1\SyncController;
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
        Route::get('/catalog/references', [CatalogController::class, 'references']);
        Route::post('/products', [CatalogController::class, 'storeProduct']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/finance/references', [FinanceReferenceController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/locations', [LocationController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::post('/sales', [SalesController::class, 'store']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/returns/sales', [ReturnController::class, 'sales']);
    Route::post('/returns/purchases', [ReturnController::class, 'purchases']);
        Route::get('/trips', [TripController::class, 'index']);
        Route::get('/vehicles', [TripController::class, 'vehicles']);
        Route::post('/trips', [TripController::class, 'store']);
        Route::get('/trips/{trip}/stock', [TripController::class, 'stock']);
        Route::post('/trips/{trip}/customers', [TripController::class, 'assignCustomer']);
        Route::post('/trip-loads', [TripLoadController::class, 'store']);
        Route::post('/trip-settlements', [TripSettlementController::class, 'store']);
        Route::post('/sync/device', [SyncController::class, 'registerDevice']);
        Route::post('/sync/operations', [SyncController::class, 'store']);
        Route::get('/field/today', [FieldController::class, 'today']);
        Route::post('/field/visits', [FieldController::class, 'visit']);
        Route::post('/field/customers', [FieldController::class, 'createCustomer']);
    });
});