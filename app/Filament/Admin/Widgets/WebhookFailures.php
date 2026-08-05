<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\WebhookStatus;
use App\Models\WebhookEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * 詳細設計11.6。1件以上で赤（NFR 6.3）。
 */
class WebhookFailures extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $webhookFailures = WebhookEvent::query()->where('status', WebhookStatus::Failed)->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $total = $webhookFailures + $failedJobs;

        return [
            Stat::make('Webhook失敗件数', (string) $total)
                ->description("Webhook={$webhookFailures} / failed_jobs={$failedJobs}")
                ->color($total >= 1 ? 'danger' : 'success'),
        ];
    }
}
