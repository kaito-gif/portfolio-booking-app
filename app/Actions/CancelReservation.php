<?php

namespace App\Actions;

use App\Enums\CancelledBy;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationNotCancellableException;
use App\Jobs\AdjustShopifyInventory;
use App\Jobs\SendReservationMail;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancelReservation
{
    /** @throws ReservationNotCancellableException */
    public function execute(
        Reservation $reservation,
        CancelledBy $by,
        ?User $actor = null,
        bool $restoreInventory = true,
        bool $sendCancelledMail = true,
    ): Reservation {
        $this->assertCancellable($reservation, $by);

        $wasConfirmed = $reservation->status === ReservationStatus::Confirmed;

        DB::transaction(function () use ($reservation, $by, $actor) {
            $reservation->cancel($by);

            AuditLog::record(
                action: 'reservation.cancelled',
                actorLabel: $this->actorLabel($by, $actor),
                auditableType: 'Reservation',
                auditableId: $reservation->id,
                changes: ['by' => $by->value],
                userId: $actor?->id,
            );
        });

        if ($restoreInventory && $wasConfirmed) {
            AdjustShopifyInventory::dispatch($reservation->slot_id, 1, 'cancellation', $reservation->id);
        }

        if ($sendCancelledMail) {
            SendReservationMail::dispatch('cancelled', [$reservation->id], $reservation->email);
        }

        return $reservation;
    }

    private function assertCancellable(Reservation $reservation, CancelledBy $by): void
    {
        if ($by === CancelledBy::System) {
            return;
        }

        if ($by === CancelledBy::Customer && ! $reservation->isCancellableByCustomer()) {
            throw new ReservationNotCancellableException("Reservation#{$reservation->id} はキャンセル期限を過ぎています");
        }

        if ($by === CancelledBy::Staff && $reservation->status !== ReservationStatus::Confirmed) {
            throw new ReservationNotCancellableException("Reservation#{$reservation->id} は確定状態ではないためキャンセルできません");
        }
    }

    private function actorLabel(CancelledBy $by, ?User $actor): string
    {
        if ($by === CancelledBy::System) {
            return 'system:rollback';
        }

        if ($actor !== null) {
            return "{$actor->name}（{$actor->role->label()}）";
        }

        return $by->label();
    }
}
