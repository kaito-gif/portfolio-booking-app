<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Enums\SlotStatus;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章。開催済み枠の確定予約を無断欠席にする。
 * slots:complete の後に回す必要がある（枠が completed になってから判定するため）。
 */
class ReservationsMarkNoShow extends Command
{
    protected $signature = 'reservations:mark-no-show';

    protected $description = '開催済み枠の確定予約を無断欠席にする';

    public function handle(): int
    {
        $count = 0;

        Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereHas('slot', fn ($query) => $query->where('status', SlotStatus::Completed))
            ->each(function (Reservation $reservation) use (&$count) {
                $reservation->markNoShow();
                $count++;
            });

        $this->info("reservations:mark-no-show processed={$count}");
        Log::info('reservations:mark-no-show', ['processed' => $count]);

        return self::SUCCESS;
    }
}
