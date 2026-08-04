<?php

namespace App\Services\Shopify;

use App\Contracts\InventoryServiceContract;
use App\Models\Slot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 段階1限定の仮実装。Shopify を実際には呼ばず常に成功を返す。
 * 段階2で ShopifyClient を使う実装に差し替え、AppServiceProvider のバインドを変更する。
 */
final class FakeInventoryService implements InventoryServiceContract
{
    public function resolveInventoryItemId(string $variantId): string
    {
        return 'fake-inventory-item-'.Str::uuid();
    }

    public function adjust(Slot $slot, int $delta, string $reason): int
    {
        return max(0, $slot->capacity - $slot->confirmedCount());
    }

    public function set(Slot $slot, int $quantity): void
    {
        // 段階1では未接続のため何もしない
    }

    public function fetchAvailable(Collection $slots): array
    {
        return $slots->mapWithKeys(fn (Slot $slot) => [
            $slot->id => max(0, $slot->capacity - $slot->confirmedCount()),
        ])->all();
    }
}
