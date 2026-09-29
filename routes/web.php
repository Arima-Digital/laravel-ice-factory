<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DeliveryItemController;
use App\Http\Controllers\Admin\IotController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductionController;
use App\Http\Controllers\Admin\SaleController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Api\IotWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Step 1 already moved to /api (routes/api.php)
|--------------------------------------------------------------------------
| Public auth, users, products, stores, vehicles, warehouses and freezers
| now live in routes/api.php. Routes below stay on /admin until their own
| step moves them.
*/

// ============================================================================
// PROTECTED ROUTES
// Admin can access all protected endpoints.
// Warehouse and Driver get only their allowed subsets.
// ============================================================================
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // Dashboard & Reporting (ADMIN only)
    Route::get('dashboard/summary', [DashboardController::class, 'getSummary'])->middleware('role:ADMIN');
    Route::get('dashboard/daily', [DashboardController::class, 'getDailyBreakdown'])->middleware('role:ADMIN');
    Route::get('dashboard/weekly', [DashboardController::class, 'getWeeklySummary'])->middleware('role:ADMIN');
    Route::get('dashboard/monthly-pl', [DashboardController::class, 'getMonthlyPL'])->middleware('role:ADMIN');

    // Settlement & Outstanding (ADMIN only)
    Route::get('settlements/statistics', [SettlementController::class, 'getStatistics'])->middleware('role:ADMIN');
    Route::get('settlements/outstanding/summary', [SettlementController::class, 'getOutstandingSummary'])->middleware('role:ADMIN');
    Route::get('settlements/overdue', [SettlementController::class, 'getOverdue'])->middleware('role:ADMIN');
    Route::get('stores/{storeId}/settlement', [SettlementController::class, 'getOutstanding'])->middleware('role:ADMIN');
    Route::get('stores/{storeId}/settlement-history', [SettlementController::class, 'getSettlementHistory'])->middleware('role:ADMIN');

    // Payment Management
    Route::post('payments/collect', [PaymentController::class, 'collectPayment'])->middleware('role:ADMIN,DRIVER');
    Route::post('payments/{id}/upload-receipt', [PaymentController::class, 'uploadReceipt'])->middleware('role:ADMIN,DRIVER');
    Route::post('payments/{id}/confirm', [PaymentController::class, 'confirmPayment'])->middleware('role:ADMIN');
    Route::get('payments/summary/all', [PaymentController::class, 'getSummary'])->middleware('role:ADMIN');
    Route::get('payments/pending/all', [PaymentController::class, 'getPending'])->middleware('role:ADMIN');
    Route::get('stores/{storeId}/payments', [PaymentController::class, 'getByStore'])->middleware('role:ADMIN');

    // Sales reporting (ADMIN only)
    Route::get('sales', [SaleController::class, 'index'])->middleware('role:ADMIN');
    Route::get('sales/{id}', [SaleController::class, 'show'])->middleware('role:ADMIN');
    Route::get('sales/summary/all', [SaleController::class, 'getSummary'])->middleware('role:ADMIN');
    Route::get('sales/date/{date}', [SaleController::class, 'getByDate'])->middleware('role:ADMIN');
    Route::get('sales/status/{status}', [SaleController::class, 'getByStatus'])->middleware('role:ADMIN');
    Route::post('sales/{id}/approve', [SaleController::class, 'approve'])->middleware('role:ADMIN');
    Route::post('sales/{id}/reject', [SaleController::class, 'reject'])->middleware('role:ADMIN');
    Route::get('stores/{storeId}/sales', [SaleController::class, 'getByStore'])->middleware('role:ADMIN');
    Route::get('freezers/{freezerId}/sales', [SaleController::class, 'getByFreezer'])->middleware('role:ADMIN');

    // Production Management (ADMIN + WAREHOUSE)
    // PATCH is deliberately not offered, PUT is the documented update method.
    Route::get('productions', [ProductionController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('productions', [ProductionController::class, 'store'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('productions/{id}', [ProductionController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::put('productions/{id}', [ProductionController::class, 'update'])->middleware('role:ADMIN,WAREHOUSE');
    Route::delete('productions/{id}', [ProductionController::class, 'destroy'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('productions/{id}/post', [ProductionController::class, 'post'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('productions/date/{date}', [ProductionController::class, 'getByDate'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('productions/status/{status}', [ProductionController::class, 'getByStatus'])->middleware('role:ADMIN,WAREHOUSE');

    // Delivery Management
    Route::get('deliveries', [DeliveryController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('deliveries/{id}', [DeliveryController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::post('deliveries', [DeliveryController::class, 'store'])->middleware('role:ADMIN,WAREHOUSE');
    Route::put('deliveries/{id}', [DeliveryController::class, 'update'])->middleware('role:ADMIN,WAREHOUSE');
    Route::delete('deliveries/{id}', [DeliveryController::class, 'destroy'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('deliveries/{id}/start', [DeliveryController::class, 'startDelivery'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('deliveries/{id}/complete', [DeliveryController::class, 'completeDelivery'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('deliveries/{id}/summary', [DeliveryController::class, 'getSummary'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('deliveries/{deliveryId}/items', [DeliveryItemController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');

    // Delivery Items & Freezer Confirmation
    Route::post('delivery-items/confirm', [DeliveryItemController::class, 'confirmFreezer'])->middleware('role:ADMIN,DRIVER');
    Route::post('delivery-items/{id}/sales', [DeliveryItemController::class, 'recordSale'])->middleware('role:ADMIN,DRIVER');
    Route::get('freezers/{freezerId}/suggestion', [DeliveryItemController::class, 'getSuggestion'])->middleware('role:ADMIN,DRIVER');
    Route::get('delivery-items/{id}', [DeliveryItemController::class, 'show'])->middleware('role:ADMIN,DRIVER');
    Route::get('stores/{storeId}/delivery-items', [DeliveryItemController::class, 'getByStore'])->middleware('role:ADMIN,DRIVER');
    Route::get('freezers/{freezerId}/delivery-items', [DeliveryItemController::class, 'getByFreezer'])->middleware('role:ADMIN,DRIVER');

    // IoT Integration (ADMIN only) - monitoring, mock for testing, manual trigger
    Route::get('iot/test-data', [IotController::class, 'testData'])->middleware('role:ADMIN');
    Route::get('iot/status', [IotController::class, 'status'])->middleware('role:ADMIN');
    Route::post('iot/logs', [IotWebhookController::class, 'store'])->middleware('role:ADMIN'); // manual trigger (same logic as webhook)
});
