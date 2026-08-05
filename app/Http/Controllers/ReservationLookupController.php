<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupReservationRequest;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * C-1 予約照会（詳細設計9・10.1）。
 */
class ReservationLookupController extends Controller
{
    public function form(Request $request): View
    {
        return view('reservations.lookup', [
            'code' => (string) $request->query('code', ''),
        ]);
    }

    public function submit(LookupReservationRequest $request): RedirectResponse
    {
        $reservation = Reservation::query()->where('code', $request->string('code'))->first();

        // 存在しない予約番号と、メールが一致しない場合とで、文言も応答時間も変えない
        // （設計8.3・NFR5.1。予約番号の存在を漏らさないため）。
        $matches = $reservation !== null && hash_equals(
            mb_strtolower($reservation->email),
            mb_strtolower((string) $request->string('email')),
        );

        if (! $matches) {
            usleep(random_int(80_000, 120_000));

            return back()
                ->withInput($request->only('code', 'email'))
                ->withErrors(['code' => '予約番号またはメールアドレスが一致しません']);
        }

        $signedUrl = URL::temporarySignedRoute(
            'reservation.show',
            now()->addMinutes(30),
            ['reservation' => $reservation->id],
        );

        return redirect($signedUrl);
    }
}
