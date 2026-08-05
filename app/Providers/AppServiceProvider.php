<?php

namespace App\Providers;

use App\Contracts\InventoryServiceContract;
use App\Services\Shopify\InventoryService;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // 詳細設計9章・design.md 8.3。予約番号ごと10分5回・IPごと10分30回の二本立て
        // （要件7.2）。IPだけで絞ると同じ回線の複数閲覧者を巻き込むため二本にする。
        RateLimiter::for('lookup', function (Request $request) {
            $code = strtoupper(trim((string) $request->input('code')));

            return [
                Limit::perMinutes(10, 5)->by('lookup-code:'.$code),
                Limit::perMinutes(10, 30)->by('lookup-ip:'.$request->ip()),
            ];
        });
    }
}
