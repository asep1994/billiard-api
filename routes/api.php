<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BilliardTableController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Api\Customer\ConfigController as CustomerConfigController;
use App\Http\Controllers\Api\Customer\DeviceTokenController as CustomerDeviceTokenController;
use App\Http\Controllers\Api\Customer\FavoriteController as CustomerFavoriteController;
use App\Http\Controllers\Api\Customer\NotificationController as CustomerNotificationController;
use App\Http\Controllers\Api\Customer\PromotionController as CustomerPromotionController;
use App\Http\Controllers\Api\Customer\ReviewController as CustomerReviewController;
use App\Http\Controllers\Api\Customer\VenueController as CustomerVenueController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/payments/callback', [PaymentController::class, 'callback'])->name('payments.callback');

    Route::middleware(['auth:sanctum', 'admin.user'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('vendors', VendorController::class);
        Route::apiResource('venues', VenueController::class);
        Route::post('venues/{venue}/photo', [VenueController::class, 'uploadPhoto']);
        Route::get('venues/{venue}/available-tables', [BilliardTableController::class, 'available']);
        Route::apiResource('tables', BilliardTableController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('bookings', BookingController::class);
        Route::post('bookings/{booking}/pay', [PaymentController::class, 'initiate']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::apiResource('promotions', PromotionController::class);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::apiResource('users', UserController::class);
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('commissions', [CommissionController::class, 'index']);
        Route::get('payouts', [PayoutController::class, 'index']);
        Route::post('payouts', [PayoutController::class, 'store']);
        Route::apiResource('reviews', ReviewController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // Customer-facing API (Flutter app): a platform-wide account that can
    // browse and book at any vendor, kept fully separate from the admin
    // dashboard's auth via the `customer.account` / `admin.user` guards.
    Route::prefix('customer')->group(function (): void {
        Route::post('/register', [CustomerAuthController::class, 'register']);
        Route::post('/login', [CustomerAuthController::class, 'login']);
        Route::post('/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/reset-password', [CustomerAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

        Route::get('/venues', [CustomerVenueController::class, 'index']);
        Route::get('/venues/{venue}', [CustomerVenueController::class, 'show']);
        Route::get('/venues/{venue}/available-tables', [CustomerVenueController::class, 'availableTables']);
        Route::get('/venues/{venue}/reviews', [CustomerReviewController::class, 'index']);
        Route::get('/promotions', [CustomerPromotionController::class, 'index']);
        Route::get('/config', [CustomerConfigController::class, 'index']);

        Route::middleware(['auth:sanctum', 'customer.account'])->group(function (): void {
            Route::post('/logout', [CustomerAuthController::class, 'logout']);
            Route::get('/me', [CustomerAuthController::class, 'me']);

            Route::get('/bookings', [CustomerBookingController::class, 'index']);
            Route::post('/bookings', [CustomerBookingController::class, 'store']);
            Route::get('/bookings/{booking}', [CustomerBookingController::class, 'show']);
            Route::post('/bookings/{booking}/pay', [CustomerBookingController::class, 'pay']);
            Route::post('/bookings/{booking}/refresh-payment', [CustomerBookingController::class, 'refreshPayment']);
            Route::post('/bookings/{booking}/review', [CustomerBookingController::class, 'review']);

            Route::get('/favorites', [CustomerFavoriteController::class, 'index']);
            Route::post('/venues/{venue}/favorite', [CustomerFavoriteController::class, 'store']);
            Route::delete('/venues/{venue}/favorite', [CustomerFavoriteController::class, 'destroy']);

            Route::post('/device-tokens', [CustomerDeviceTokenController::class, 'store']);
            Route::delete('/device-tokens', [CustomerDeviceTokenController::class, 'destroy']);

            Route::get('/notifications', [CustomerNotificationController::class, 'index']);
            Route::post('/notifications/read-all', [CustomerNotificationController::class, 'markAllAsRead']);
            Route::post('/notifications/{notification}/read', [CustomerNotificationController::class, 'markAsRead']);
        });
    });
});
