<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class ReservationConfirmedMail extends Mailable
{
    use Queueable;

    public const SUBJECT = '【chanoka】ワークショップのご予約を承りました';

    /** @param  Collection<int, Reservation>  $reservations */
    public function __construct(public readonly Collection $reservations) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECT);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.reservation-confirmed',
            with: ['reservations' => $this->reservations],
        );
    }
}
