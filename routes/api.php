<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\FreezerController;
use App\Http\Controllers\Admin\ProductController;
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


