<?php

namespace App\Contracts;

use App\Models\Slot;
use Illuminate\Support\Collection;

/**
 * 段階1時点では未接続（詳細設計7.2）。段階2で Shopify GraphQL を叩く実装
 * （App\Services\Shopify\InventoryService）に差し替える。
 */
interface InventoryServiceContract
{
    public function resolveInventoryItemId(string $variantId): string;

    public function adjust(Slot $slot, int $delta, string $reason): int;

    public function set(Slot $slot, int $quantity): void;

    /** @return array<int, int> [slotId => available] */
    public function fetchAvailable(Collection $slots): array;
}
