<?php

namespace App\Providers;

use App\Auth\BusStoreUserProvider;
use App\Services\BusStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('bus-store', fn ($app) => new BusStoreUserProvider($app->make(BusStore::class)));
    }
}
