<?php

namespace App\Filament\Admin\Resources\Slots\Pages;

use App\Contracts\InventoryServiceContract;
use App\Filament\Admin\Resources\Slots\SlotResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlot extends EditRecord
{
    protected static string $resource = SlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if (! $this->record->wasChanged('shopify_variant_id') || $this->record->shopify_variant_id === null) {
            return;
        }

        $inventoryItemId = app(InventoryServiceContract::class)
            ->resolveInventoryItemId($this->record->shopify_variant_id);

        $this->record->forceFill(['shopify_inventory_item_id' => $inventoryItemId])->saveQuietly();
    }
}
