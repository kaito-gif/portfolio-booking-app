<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReservationCancelledMail extends Mailable
{
    use Queueable;

    public const SUBJECT = '【chanoka】ご予約のキャンセルを承りました';

    public function __construct(public readonly Reservation $reservation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.reservation-cancelled',
            with: ['reservation' => $this->reservation],
        );
    }
}
