<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\Webhooks\ShopifyOrderController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

Route::get('/health', HealthCheckController::class)->name('health');

Route::post('/webhooks/shopify/orders-create', [ShopifyOrderController::class, 'ordersCreate'])
    ->middleware('shopify.hmac')
    ->name('webhook.orders');
