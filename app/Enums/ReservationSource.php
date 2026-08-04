<?php

namespace App\Enums;

enum ReservationSource: string
{
    case Shopify = 'shopify';
    case Manual = 'manual';
    case Seed = 'seed';

    public function label(): string
    {
        return match ($this) {
            self::Shopify => 'Shopify',
            self::Manual => '手動登録',
            self::Seed => 'シード',
        };
    }
}
