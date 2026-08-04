<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case InventoryPending = 'inventory_pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::InventoryPending => '在庫確保待ち',
            self::Confirmed => '確定',
            self::Cancelled => 'キャンセル済み',
            self::Attended => '参加済み',
            self::NoShow => '無断欠席',
        };
    }
}
