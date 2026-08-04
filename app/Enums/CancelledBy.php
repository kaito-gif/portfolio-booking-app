<?php

namespace App\Enums;

enum CancelledBy: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => '顧客',
            self::Staff => 'スタッフ',
            self::System => 'システム',
        };
    }
}
