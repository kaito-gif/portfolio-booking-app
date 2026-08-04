<?php

namespace App\Actions;

use App\Exceptions\CheckInNotRevertibleException;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\User;

final class CheckInReservation
{
    public function execute(Reservation $reservation, ?User $actor = null): Reservation
    {
        $reservation->checkIn();

        AuditLog::record(
            action: 'reservation.checked_in',
            actorLabel: $actor === null ? 'system' : "{$actor->name}（{$actor->role->label()}）",
            auditableType: 'Reservation',
            auditableId: $reservation->id,
            userId: $actor?->id,
        );

        return $reservation;
    }

    /**
     * 誤チェックインの取り消し。詳細設計5.4により管理者のみ許可する。
     *
     * @throws CheckInNotRevertibleException 管理者以外からの呼び出し
     */
    public function revert(Reservation $reservation, ?User $actor = null): Reservation
    {
        if ($actor === null || ! $actor->isAdmin()) {
            throw new CheckInNotRevertibleException("Reservation#{$reservation->id}: チェックインの取り消しは管理者のみ操作できます");
        }

        $reservation->revertCheckIn();

        AuditLog::record(
            action: 'reservation.check_in_reverted',
            actorLabel: $actor === null ? 'system' : "{$actor->name}（{$actor->role->label()}）",
            auditableType: 'Reservation',
            auditableId: $reservation->id,
            userId: $actor?->id,
        );

        return $reservation;
    }
}
