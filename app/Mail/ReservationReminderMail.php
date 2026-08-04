<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReservationReminderMail extends Mailable
{
    use Queueable;

    public const SUBJECT = '【chanoka】明日のワークショップのご案内';

    public function __construct(public readonly Reservation $reservation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.reservation-reminder',
            with: ['reservation' => $this->reservation],
        );
    }
}
