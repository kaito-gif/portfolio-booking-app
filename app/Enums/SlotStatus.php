<?php

namespace App\Enums;

enum SlotStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Open => '受付中',
            self::Closed => '締切',
            self::Cancelled => '中止',
            self::Completed => '開催済み',
        };
    }
}
