<?php

namespace App\Providers;

use App\Models\Pago;
use App\Observers\PagoObserver;
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
        Pago::observe(PagoObserver::class);
    }
}
