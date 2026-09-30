<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DeliveryItemController;
use App\Http\Controllers\Admin\FreezerController;
use App\Http\Controllers\Admin\IotController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductionController;
use App\Http\Controllers\Admin\SaleController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Api\IotWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * API Routes for ICA Factory MVP
 * Prefix: /api
 *
 * Roles are enforced by the token, not by the URL. A DRIVER may call
 * /api/stores and still get 403 on /api/products.
 */

// ============================================================================
// PUBLIC AUTHENTICATION ROUTES (No Sanctum middleware required)
//   - POST /api/auth/login      : login and receive a Sanctum token
//   - POST /api/auth/logout     : revoke the token used by this request
//   - POST /api/auth/refresh    : revoke the current token, issue a new one
//   - GET  /api/auth/me         : current authenticated user
//   - POST /api/auth/logout-all : revoke every token of the user
// CSRF does not apply to /api routes.
// ============================================================================
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('auth:sanctum');
});

// ============================================================================
// PROTECTED ROUTES - STEP 1: Auth & Master Data
// CSRF does not apply to /api routes, so these are callable from any origin.
// ============================================================================
Route::middleware('auth:sanctum')->group(function () {
    // Drivers (ADMIN, WAREHOUSE) - dropdown source for delivery plans
    Route::get('drivers', [UserController::class, 'getDrivers'])->middleware('role:ADMIN,WAREHOUSE');

    // User Management (ADMIN only)
    // PATCH is deliberately not offered, PUT is the documented update method.
    Route::resource('users', UserController::class)
        ->only(['index', 'store', 'show', 'destroy'])
        ->middleware('role:ADMIN');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('role:ADMIN');
    Route::get('users/role/{role}', [UserController::class, 'getByRole'])->middleware('role:ADMIN');

    // Master Data Management - Products
    Route::get('products', [ProductController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('products/{id}', [ProductController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('products', [ProductController::class, 'store'])->middleware('role:ADMIN');
    Route::put('products/{id}', [ProductController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('products/{id}', [ProductController::class, 'destroy'])->middleware('role:ADMIN');

    // Master Data Management - Stores (DRIVER may read for the store visit)
    Route::get('stores', [StoreController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('stores/{id}', [StoreController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::post('stores', [StoreController::class, 'store'])->middleware('role:ADMIN');
    Route::put('stores/{id}', [StoreController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('stores/{id}', [StoreController::class, 'destroy'])->middleware('role:ADMIN');
    Route::get('stores/{storeId}/freezers', [FreezerController::class, 'getByStore'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');

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

    // Master Data Management - Freezers
    Route::get('freezers', [FreezerController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');
    Route::get('freezers/{id}', [FreezerController::class, 'show'])->middleware('role:ADMIN,WAREHOUSE');
    Route::post('freezers', [FreezerController::class, 'store'])->middleware('role:ADMIN');
    Route::put('freezers/{id}', [FreezerController::class, 'update'])->middleware('role:ADMIN');
    Route::delete('freezers/{id}', [FreezerController::class, 'destroy'])->middleware('role:ADMIN');

    // =========================================================================
    // TRANSACTIONS & REPORTING (moved from /admin, see routes/web.php)
    // Role enforcement is identical to before. Only the URL prefix changed:
    // /admin/x became /api/x. Paths, verbs and middleware are untouched so the
    // frontend contract is otherwise the same.
    // =========================================================================

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
    Route::get('deliveries/{id}/route', [DeliveryController::class, 'getRoute'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('deliveries/{deliveryId}/items', [DeliveryItemController::class, 'index'])->middleware('role:ADMIN,WAREHOUSE');

    // Arriving, leaving and settling are steps the driver takes at the shop, so
    // the driver is on these alongside admin and warehouse staff. Skipping is
    // here too: a shop that is shut has to be passable, and a run cannot be
    // closed while a stop is still open.
    Route::post('deliveries/{id}/stops/{storeId}/arrive', [DeliveryController::class, 'arriveAtStop'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::post('deliveries/{id}/stops/{storeId}/depart', [DeliveryController::class, 'departFromStop'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::post('deliveries/{id}/stops/{storeId}/skip', [DeliveryController::class, 'skipStop'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');
    Route::get('deliveries/{id}/stops/{storeId}/settlement-preview', [DeliveryController::class, 'stopSettlementPreview'])->middleware('role:ADMIN,WAREHOUSE,DRIVER');

    // Delivery Items & Freezer Confirmation
    Route::post('delivery-items/confirm', [DeliveryItemController::class, 'confirmFreezer'])->middleware('role:ADMIN,DRIVER');
    Route::post('delivery-items/{id}/sales', [DeliveryItemController::class, 'recordSale'])->middleware('role:ADMIN,DRIVER');
    Route::get('freezers/{freezerId}/suggestion', [DeliveryItemController::class, 'getSuggestion'])->middleware('role:ADMIN,DRIVER');
    Route::get('delivery-items/{id}', [DeliveryItemController::class, 'show'])->middleware('role:ADMIN,DRIVER');
    Route::get('stores/{storeId}/delivery-items', [DeliveryItemController::class, 'getByStore'])->middleware('role:ADMIN,DRIVER');
    Route::get('freezers/{freezerId}/delivery-items', [DeliveryItemController::class, 'getByFreezer'])->middleware('role:ADMIN,DRIVER');

    // IoT monitoring & manual mock (ADMIN only)
    // The public webhook below already exposes POST /api/iot/logs, so there is
    // deliberately no admin-only duplicate of that path here.
    Route::get('iot/test-data', [IotController::class, 'testData'])->middleware('role:ADMIN');
    Route::get('iot/status', [IotController::class, 'status'])->middleware('role:ADMIN');
});

// ============================================================================
// PUBLIC IOT ENDPOINTS (plain, no auth middleware on purpose)
//   - POST /api/iot/logs  : device pushes a telemetry reading (PUSH)
//   - GET|POST /api/iot/sync : trigger a PULL cycle (Dokploy scheduler hits this)
// CSRF does not apply to /api routes.
// ============================================================================
Route::prefix('iot')->group(function () {
    Route::post('/logs', [IotWebhookController::class, 'store']); // PUSH model
    Route::match(['get', 'post'], '/sync', [IotWebhookController::class, 'sync']); // PULL model trigger
});
