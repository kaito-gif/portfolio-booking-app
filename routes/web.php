<?php

use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

Route::get('/health', HealthCheckController::class)->name('health');
