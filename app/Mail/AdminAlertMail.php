<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 詳細設計14章。管理者向けの障害・在庫差分通知。
 */
class AdminAlertMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $alertSubject,
        public readonly string $bodyText,
        public readonly string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.admin-alert',
            with: ['bodyText' => $this->bodyText, 'adminUrl' => $this->adminUrl],
        );
    }
}
