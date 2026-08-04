<?php

namespace App\Enums;

enum CancelledBy: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => '顧客',
            self::Staff => 'スタッフ',
            self::Admin => '管理者',
            self::System => 'システム',
        };
    }
}
