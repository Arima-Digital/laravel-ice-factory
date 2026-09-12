<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DeliveryItemController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductionController;
use App\Http\Controllers\Admin\SaleController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// ============================================================================
// PUBLIC AUTHENTICATION ROUTES (No Sanctum middleware required)
// ============================================================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']); // Login & get token
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum'); // Logout
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum'); // Refresh token
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum'); // Get current user
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('auth:sanctum'); // Logout from all devices
});

// ============================================================================
// PROTECTED ROUTES
// Admin can access all protected endpoints.
// Warehouse and Driver get only their allowed subsets.
// ============================================================================
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    // User Management (ADMIN only)
    Route::resource('users', UserController::class)->middleware('role:ADMIN');
    Route::get('/users/role/{role}', [UserController::class, 'getByRole'])->middleware('role:ADMIN');
    Route::post('/users/register', [AuthController::class, 'register'])->middleware('role:ADMIN');

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
    Route::get('stores/{storeId}/sales', [SaleController::class, 'getByStore'])->middleware('role:ADMIN');
    Route::get('freezers/{freezerId}/sales', [SaleController::class, 'getByFreezer'])->middleware('role:ADMIN');

    // Production Management (ADMIN + WAREHOUSE)
    Route::resource('productions', ProductionController::class)->middleware('role:ADMIN,WAREHOUSE');
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
    Route::get('freezers/{freezerId}/suggestion', [DeliveryItemController::class, 'getSuggestion'])->middleware('role:ADMIN,DRIVER');
    Route::get('delivery-items/{id}', [DeliveryItemController::class, 'show'])->middleware('role:ADMIN,DRIVER');
    Route::get('stores/{storeId}/delivery-items', [DeliveryItemController::class, 'getByStore'])->middleware('role:ADMIN,DRIVER');
    Route::get('freezers/{freezerId}/delivery-items', [DeliveryItemController::class, 'getByFreezer'])->middleware('role:ADMIN,DRIVER');

    // Master Data Management - Products
    Route::get('products', [ProductController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('products', [ProductController::class, 'store'])->middleware('role:ADMIN');
    Route::put('products/{id}', [ProductController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('products/{id}', [ProductController::class, 'destroy'])->middleware('role:ADMIN');

    // Master Data Management - Stores
    Route::get('stores', [StoreController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('stores/{id}', [StoreController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::post('stores', [StoreController::class, 'store'])->middleware('role:ADMIN');
    Route::put('stores/{id}', [StoreController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('stores/{id}', [StoreController::class, 'destroy'])->middleware('role:ADMIN');

    // Master Data Management - Vehicles
    Route::get('vehicles', [VehicleController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('vehicles/{id}', [VehicleController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('vehicles', [VehicleController::class, 'store'])->middleware('role:ADMIN');
    Route::put('vehicles/{id}', [VehicleController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('vehicles/{id}', [VehicleController::class, 'destroy'])->middleware('role:ADMIN');

    // Master Data Management - Warehouses
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('role:ADMIN');
    Route::put('warehouses/{id}', [WarehouseController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('warehouses/{id}', [WarehouseController::class, 'destroy'])->middleware('role:ADMIN');
    Route::get('warehouses/{id}/available-stock', [WarehouseController::class, 'getAvailableStock'])->middleware('role:ADMIN,WAREHOUSE');
});
