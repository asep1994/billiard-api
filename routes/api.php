<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BilliardTableController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('vendors', VendorController::class);
        Route::apiResource('venues', VenueController::class);
        Route::get('venues/{venue}/available-tables', [BilliardTableController::class, 'available']);
        Route::apiResource('tables', BilliardTableController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('bookings', BookingController::class);
        Route::apiResource('users', UserController::class);
    });
});
