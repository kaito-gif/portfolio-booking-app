<?php

namespace App\Filament\Admin\Widgets;

use App\Console\Commands\InventoryCheck as InventoryCheckCommand;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * 詳細設計11.6。画面表示のたびにShopifyを叩かず、inventory:checkが
 * 書いたcacheを読むだけにする（同時閲覧でレート制限に触れるため）。
 */
class InventoryDrift extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $result = Cache::get(InventoryCheckCommand::CACHE_KEY);

        if ($result === null) {
            return [
                Stat::make('在庫差分', '未確認')
                    ->description('inventory:check がまだ実行されていません'),
            ];
        }

        $count = count($result['drifted']);
        $checkedAt = CarbonImmutable::parse($result['checked_at']);

        return [
            Stat::make('在庫差分', (string) $count)
                ->description('最終確認: '.$checkedAt->format('Y-m-d H:i'))
                ->color($count >= 1 ? 'danger' : 'success'),
        ];
    }
}
