<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Support\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * 第三者レビュー指摘への対応: 通知メール自体の送信失敗（SMTP停止等）が
 * 元の障害と重なる二重障害のケースでも、最低限ログに残ることを確認する。
 */
class AdminNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_logs_when_mail_send_fails(): void
    {
        config(['booking.admin_notification_email' => 'admin@example.com']);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('smtp down'));

        Log::shouldReceive('error')
            ->once()
            ->with('admin notification failed to send', \Mockery::on(
                fn (array $context) => $context['subject'] === 'テスト通知'
                    && $context['suppression_key'] === 'test:key'
                    && $context['exception'] === 'smtp down',
            ));

        AdminNotifier::notify(
            suppressionKey: 'test:key',
            subject: 'テスト通知',
            bodyText: '本文',
            adminUrl: 'https://example.com/admin',
        );
    }

    public function test_notify_sends_mail_when_no_failure(): void
    {
        config(['booking.admin_notification_email' => 'admin@example.com']);
        Mail::fake();

        AdminNotifier::notify(
            suppressionKey: 'test:key2',
            subject: 'テスト通知2',
            bodyText: '本文',
            adminUrl: 'https://example.com/admin',
        );

        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo('admin@example.com'));
    }
}
