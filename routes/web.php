<?php

use App\Http\Controllers\DeviceController;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:30,1', EnsureDeviceToken::class])
    ->prefix('r1/{token}')
    ->group(function () {
        Route::get('/', [DeviceController::class, 'show'])->name('r1.show');
        Route::get('/arrivals', [DeviceController::class, 'arrivals'])->name('r1.arrivals');
    });
