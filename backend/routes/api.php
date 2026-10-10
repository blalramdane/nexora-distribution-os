<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FieldController;
use App\Http\Controllers\Api\V1\FinanceReferenceController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\SalesController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripLoadController;
use App\Http\Controllers\Api\V1\TripSettlementController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static fn () => response()->json([
    'status' => 'ok',
    'service' => 'nexora-distribution-api',
]));

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');
        Route::get('/sales/history', [SalesController::class, 'history'])->middleware('permission:sales.view');
        Route::get('/sales/invoices/{invoiceId}/items', [SalesController::class, 'invoiceItems'])->middleware('permission:sales.view');
        Route::get('/products', [CatalogController::class, 'products'])->middleware('permission:products.view');
        Route::get('/catalog/references', [CatalogController::class, 'references'])->middleware('permission:products.view');
        Route::post('/products', [CatalogController::class, 'storeProduct'])->middleware('permission:products.manage');
        Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::get('/sales/invoices', [SalesController::class, 'openInvoices'])->middleware('permission:sales.view');
        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::get('/finance/references', [FinanceReferenceController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage');
        Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.adjust');
        Route::get('/locations', [LocationController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.post');
        Route::get('/purchases/history', [PurchaseController::class, 'history'])->middleware('permission:purchases.post');
        Route::get('/purchases/invoices/{invoiceId}/items', [PurchaseController::class, 'invoiceItems'])->middleware('permission:purchases.post');
        Route::post('/sales', [SalesController::class, 'store'])->middleware('permission:sales.post');
        Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:payments.record');
        Route::post('/returns/sales', [ReturnController::class, 'sales'])->middleware('permission:sales.post');
        Route::post('/returns/purchases', [ReturnController::class, 'purchases'])->middleware('permission:purchases.post');
        Route::get('/trips', [TripController::class, 'index'])->middleware('permission:trips.view');
        Route::get('/vehicles', [TripController::class, 'vehicles'])->middleware('permission:trips.view');
        Route::post('/trips', [TripController::class, 'store'])->middleware('permission:trips.manage');
        Route::get('/trips/{trip}/stock', [TripController::class, 'stock'])->middleware('permission:trips.view');
        Route::post('/trips/{trip}/customers', [TripController::class, 'assignCustomer'])->middleware('permission:trips.manage');
        Route::get('/trips/{trip}/route', [TripController::class, 'route'])->middleware('permission:trips.view');
        Route::post('/trips/{trip}/route/optimize', [TripController::class, 'optimizeRoute'])->middleware('permission:trips.manage');
        Route::post('/trips/{trip}/route/reorder', [TripController::class, 'reorderRoute'])->middleware('permission:trips.manage');
        Route::post('/trip-loads', [TripLoadController::class, 'store'])->middleware('permission:inventory.adjust');
        Route::post('/trip-settlements', [TripSettlementController::class, 'store'])->middleware('permission:payments.record');
        Route::post('/sync/device', [SyncController::class, 'registerDevice'])->middleware('permission:field.sync');
        Route::post('/sync/operations', [SyncController::class, 'store'])->middleware('permission:field.sync');
        Route::get('/field/today', [FieldController::class, 'today'])->middleware('permission:field.visit');
        Route::post('/field/visits', [FieldController::class, 'visit'])->middleware('permission:field.visit');
        Route::post('/field/customers', [FieldController::class, 'createCustomer'])->middleware('permission:customers.manage');
    });
});
