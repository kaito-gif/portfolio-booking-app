<?php

namespace App\Contracts;

use App\Models\Slot;
use Illuminate\Support\Collection;

/**
 * 詳細設計7.2。実装は App\Services\Shopify\InventoryService
 * （Shopify GraphQL を叩く）。AppServiceProvider でバインドする。
 */
interface InventoryServiceContract
{
    public function resolveInventoryItemId(string $variantId): string;

    public function adjust(Slot $slot, int $delta, string $reason): int;

    public function set(Slot $slot, int $quantity): void;

    /** @return array<int, int> [slotId => available] */
    public function fetchAvailable(Collection $slots): array;
}
