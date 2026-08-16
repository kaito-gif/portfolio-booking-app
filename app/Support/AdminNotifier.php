<?php

namespace App\Support;

use App\Mail\AdminAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 詳細設計14章。抑止は Cache::add の戻り値で判定する（has→putの2段階にしない。
 * 同一分内に複数ジョブが失敗すると両方通り抜けるため）。
 */
final class AdminNotifier
{
    private const SUPPRESSION_SECONDS = 1800;

    /** @param  string  $suppressionKey  "webhook:{id}" のような接頭辞なしのキー。notify:を前置して使う */
    public static function notify(string $suppressionKey, string $subject, string $bodyText, string $adminUrl): void
    {
        if (! Cache::add("notify:{$suppressionKey}", true, self::SUPPRESSION_SECONDS)) {
            return;
        }

        $to = config('booking.admin_notification_email');

        if (empty($to)) {
            Log::warning('admin notification skipped: booking.admin_notification_email is not set', [
                'subject' => $subject,
                'suppression_key' => $suppressionKey,
            ]);

            return;
        }

        try {
            Mail::to($to)->send(new AdminAlertMail($subject, $bodyText, $adminUrl));
        } catch (Throwable $e) {
            // 通知メール自体の送信失敗（SMTP停止等）を握りつぶすと、元の障害と
            // あわせた二重障害が誰にも気づかれなくなる。ここでは最低限ログに残す。
            Log::error('admin notification failed to send', [
                'subject' => $subject,
                'suppression_key' => $suppressionKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
