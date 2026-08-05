<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationLookupController;
use App\Http\Controllers\Webhooks\ShopifyOrderController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

Route::get('/health', HealthCheckController::class)->name('health');

Route::get('/r/lookup', [ReservationLookupController::class, 'form'])->name('lookup.form');
Route::post('/r/lookup', [ReservationLookupController::class, 'submit'])
    ->middleware('throttle:lookup')
    ->name('lookup.submit');

Route::middleware('signed')->group(function (): void {
    Route::get('/r/{reservation}', [ReservationController::class, 'show'])->name('reservation.show');
    Route::post('/r/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservation.cancel');
});

Route::post('/webhooks/shopify/orders-create', [ShopifyOrderController::class, 'ordersCreate'])
    ->middleware('shopify.hmac')
    ->name('webhook.orders');
