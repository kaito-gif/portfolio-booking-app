<?php

namespace App\Filament\Admin\Resources\Slots\Pages;

use App\Filament\Admin\Resources\Slots\SlotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlots extends ListRecords
{
    protected static string $resource = SlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
