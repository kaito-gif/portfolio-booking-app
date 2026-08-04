<?php

namespace App\Providers;

use App\Contracts\InventoryServiceContract;
use App\Services\Shopify\FakeInventoryService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 段階2で App\Services\Shopify\InventoryService（実装）に差し替える
        $this->app->bind(InventoryServiceContract::class, FakeInventoryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
