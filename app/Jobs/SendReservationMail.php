<?php

namespace App\Jobs;

use App\Mail\ReservationCancelledMail;
use App\Mail\ReservationConfirmedMail;
use App\Mail\ReservationReminderMail;
use App\Models\MailLog;
use App\Models\Reservation;
use App\Support\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * 詳細設計12章。mail_logs へ1行残しつつ実送信する。
 * 送信失敗は mail_logs.status=failed に留め、例外を再スローしない
 * （管理画面 MailLogs の手動再送でのみリトライする運用のため）。
 */
class SendReservationMail implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int[]  $reservationIds
     * @param  int|null  $existingMailLogId  再送のときに既存の mail_logs 行を渡す（新規行を作らず更新する）
     */
    public function __construct(
        public readonly string $type,
        public readonly array $reservationIds,
        public readonly string $to,
        public readonly ?int $existingMailLogId = null,
    ) {}

    public function handle(): void
    {
        $reservations = Reservation::query()->with('slot.workshop')->whereIn('id', $this->reservationIds)->get();

        if ($reservations->isEmpty()) {
            return;
        }

        $mailable = $this->buildMailable($reservations);
        $subjectClass = $mailable::class;

        $mailLog = $this->existingMailLogId === null
            ? MailLog::create([
                'reservation_id' => $reservations->first()->id,
                'related_reservation_ids' => $this->reservationIds,
                'type' => $this->type,
                'to' => $this->to,
                'subject' => $subjectClass::SUBJECT,
                'status' => 'queued',
            ])
            : MailLog::findOrFail($this->existingMailLogId);

        try {
            Mail::to($this->to)->send($mailable);

            $mailLog->status = 'sent';
            $mailLog->body = $mailable->render();
            $mailLog->sent_at = now();
            $mailLog->last_error = null;
            $mailLog->attempts = $mailLog->attempts + 1;
            $mailLog->save();
        } catch (Throwable $e) {
            $mailLog->status = 'failed';
            $mailLog->last_error = $e->getMessage();
            $mailLog->attempts = $mailLog->attempts + 1;
            $mailLog->save();

            Log::warning('reservation mail send failed', [
                'mail_log_id' => $mailLog->id,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);

            AdminNotifier::notify(
                suppressionKey: "mail:{$mailLog->id}",
                subject: '【chanoka】メール送信が失敗しました',
                bodyText: "MailLog#{$mailLog->id}（type={$this->type}、宛先={$this->to}）の送信が失敗しました。\n{$e->getMessage()}",
                adminUrl: url('/admin/mail-logs'),
            );
        }
    }

    /** @param  Collection<int, Reservation>  $reservations */
    private function buildMailable(Collection $reservations): Mailable
    {
        return match ($this->type) {
            'confirmed' => new ReservationConfirmedMail($reservations),
            'reminder' => new ReservationReminderMail($reservations->first()),
            'cancelled' => new ReservationCancelledMail($reservations->first()),
            default => throw new InvalidArgumentException("Unknown mail type: {$this->type}"),
        };
    }
}
