<?php

namespace App\Providers;

use App\Contracts\InventoryServiceContract;
use App\Services\Shopify\InventoryService;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ShopifyClient::class, fn () => new ShopifyClient(
            shopDomain: (string) config('services.shopify.shop_domain'),
            accessToken: (string) config('services.shopify.access_token'),
            apiVersion: (string) config('services.shopify.api_version'),
        ));

        $this->app->bind(InventoryServiceContract::class, fn ($app) => new InventoryService(
            $app->make(ShopifyClient::class),
            (string) config('services.shopify.location_id'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
