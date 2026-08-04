<?php

namespace App\Filament\Admin\Resources\Slots\Pages;

use App\Contracts\InventoryServiceContract;
use App\Filament\Admin\Resources\Slots\SlotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSlot extends CreateRecord
{
    protected static string $resource = SlotResource::class;

    protected function afterCreate(): void
    {
        if ($this->record->shopify_variant_id === null) {
            return;
        }

        $inventoryItemId = app(InventoryServiceContract::class)
            ->resolveInventoryItemId($this->record->shopify_variant_id);

        $this->record->forceFill(['shopify_inventory_item_id' => $inventoryItemId])->saveQuietly();
    }
}
