<?php

namespace App\Enums;

enum UserRole: string
{
    case Staff = 'staff';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'スタッフ',
            self::Admin => '管理者',
        };
    }
}
