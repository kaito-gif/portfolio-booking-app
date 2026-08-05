<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Enums\SlotStatus;
use App\Jobs\SendReservationMail;
use App\Models\MailLog;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章。翌日開催の確定予約にリマインドメールを積む。
 * 同一予約・同一日に既に積んだ行があれば重ねて積まない（cronの二重実行対策）。
 */
class ReservationsRemind extends Command
{
    protected $signature = 'reservations:remind';

    protected $description = '翌日開催の確定予約にリマインドメールを積む';

    public function handle(): int
    {
        $tomorrow = CarbonImmutable::now()->addDay()->toDateString();
        $today = CarbonImmutable::now()->toDateString();
        $count = 0;

        $reservations = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereHas('slot', fn ($query) => $query
                ->whereIn('status', [SlotStatus::Open, SlotStatus::Closed])
                ->whereDate('starts_at', $tomorrow))
            ->get();

        foreach ($reservations as $reservation) {
            $alreadyQueuedToday = MailLog::query()
                ->where('reservation_id', $reservation->id)
                ->where('type', 'reminder')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyQueuedToday) {
                continue;
            }

            SendReservationMail::dispatch('reminder', [$reservation->id], $reservation->email);
            $count++;
        }

        $this->info("reservations:remind processed={$count}");
        Log::info('reservations:remind', ['processed' => $count]);

        return self::SUCCESS;
    }
}
