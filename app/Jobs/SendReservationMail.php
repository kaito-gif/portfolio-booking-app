<?php

namespace App\Jobs;

use App\Models\MailLog;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 本文の組み立て・実送信（Mailable・テンプレート）は段階3（詳細設計12章）で実装する。
 * 段階1では mail_logs に queued 行を残すところまでを担う。
 */
class SendReservationMail implements ShouldQueue
{
    use Queueable;

    /** @param  int[]  $reservationIds */
    public function __construct(
        public readonly string $type,
        public readonly array $reservationIds,
        public readonly string $to,
    ) {}

    public function handle(): void
    {
        $reservations = Reservation::query()->whereIn('id', $this->reservationIds)->get();

        if ($reservations->isEmpty()) {
            return;
        }

        $codes = $reservations->pluck('code')->implode(', ');

        MailLog::create([
            'reservation_id' => $reservations->first()->id,
            'related_reservation_ids' => $this->reservationIds,
            'type' => $this->type,
            'to' => $this->to,
            'subject' => "【予約{$this->type}】{$codes}",
            'status' => 'queued',
        ]);
    }
}
