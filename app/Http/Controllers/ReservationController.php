<?php

namespace App\Http\Controllers;

use App\Actions\CancelReservation;
use App\Enums\CancelledBy;
use App\Exceptions\ReservationNotCancellableException;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * C-2 予約詳細・キャンセル（詳細設計9・10.1）。署名付きURL経由でのみ開ける。
 */
class ReservationController extends Controller
{
    public function show(Reservation $reservation): View
    {
        $reservation->load('slot.workshop');

        $cancelUrl = $reservation->isCancellableByCustomer()
            ? URL::temporarySignedRoute('reservation.cancel', now()->addMinutes(30), ['reservation' => $reservation->id])
            : null;

        return view('reservations.show', ['reservation' => $reservation, 'cancelUrl' => $cancelUrl]);
    }

    public function cancel(Reservation $reservation): RedirectResponse
    {
        try {
            app(CancelReservation::class)->execute(
                reservation: $reservation,
                by: CancelledBy::Customer,
            );

            $status = 'キャンセルを承りました';
            $errors = [];
        } catch (ReservationNotCancellableException) {
            $status = null;
            $errors = ['cancel' => '開催前日23:59を過ぎたため、お電話でご連絡ください'];
        }

        // POST用の署名はこのURL専用のため、戻り先には新しい署名付きURLを発行する
        // （back()で未署名のshowへ戻すと、その場で403になる）。
        $redirectUrl = URL::temporarySignedRoute('reservation.show', now()->addMinutes(30), ['reservation' => $reservation->id]);

        return redirect($redirectUrl)->with('status', $status)->withErrors($errors);
    }
}
