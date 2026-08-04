<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workshop;

class WorkshopPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workshop $workshop): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Workshop $workshop): bool
    {
        return $user->isAdmin();
    }

    /**
     * 「配下の枠が0件」は詳細設計に明記された要求ではなく、Slot側の
     * 「予約0件の枠のみ削除可」（3.3・11.3）と対称にした設計判断による拡張。
     * FKがRESTRICTのためDB側でも同じ結果になる。
     */
    public function delete(User $user, Workshop $workshop): bool
    {
        return $user->isAdmin() && $workshop->slots()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
