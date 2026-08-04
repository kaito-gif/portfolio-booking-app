<?php

namespace App\Policies;

use App\Models\Slot;
use App\Models\User;

class SlotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Slot $slot): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Slot $slot): bool
    {
        return $user->isAdmin();
    }

    /** 削除は予約0件の枠のみ（詳細設計3.3・11.3）。 */
    public function delete(User $user, Slot $slot): bool
    {
        return $user->isAdmin() && $slot->reservations()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
