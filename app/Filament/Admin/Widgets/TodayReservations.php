<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 詳細設計11.6。
 */
class TodayReservations extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $createdToday = Reservation::query()
            ->whereDate('created_at', $today)
            ->count();

        $confirmedForToday = Reservation::query()
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::Attended, ReservationStatus::NoShow])
            ->whereHas('slot', fn ($query) => $query->whereDate('starts_at', $today))
            ->count();

        return [
            Stat::make('本日作成された予約数', (string) $createdToday),
            Stat::make('本日開催の確定数', (string) $confirmedForToday),
        ];
    }
}
