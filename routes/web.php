<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\LoginController;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:30,1,device', EnsureDeviceToken::class])
    ->prefix('r1/{token}')
    ->group(function () {
        Route::get('/', [DeviceController::class, 'show'])->name('r1.show');
        Route::get('/arrivals', [DeviceController::class, 'arrivals'])->name('r1.arrivals');
    });

Route::redirect('/', '/admin');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1,login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::view('/admin', 'admin')->name('admin');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
