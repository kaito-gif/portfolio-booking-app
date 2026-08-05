<?php

namespace App\Policies;

use App\Models\User;

/**
 * 詳細設計11.1。ユーザー管理はadminのみ。デモユーザー(is_demo)は
 * update/delete/updatePassword/changeRoleのいずれも拒否する(要件7.4)。
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isDemo();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isDemo();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function updatePassword(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isDemo();
    }

    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->isDemo();
    }
}
