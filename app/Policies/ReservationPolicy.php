<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return true;
    }

    /**
     * 予約は物理削除しない（詳細設計3.1「論理削除は使わない。予約は status で
     * 表現し、行は消さない」）。staff/admin を問わず常に拒否する。
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
