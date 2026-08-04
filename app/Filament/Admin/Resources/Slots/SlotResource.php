<?php

namespace App\Filament\Admin\Resources\Slots;

use App\Filament\Admin\Resources\Slots\Pages\CreateSlot;
use App\Filament\Admin\Resources\Slots\Pages\EditSlot;
use App\Filament\Admin\Resources\Slots\Pages\ListSlots;
use App\Filament\Admin\Resources\Slots\Schemas\SlotForm;
use App\Filament\Admin\Resources\Slots\Tables\SlotsTable;
use App\Models\Slot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SlotResource extends Resource
{
    protected static ?string $model = Slot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = '開催枠';

    protected static ?string $modelLabel = '開催枠';

    public static function form(Schema $schema): Schema
    {
        return SlotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SlotsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSlots::route('/'),
            'create' => CreateSlot::route('/create'),
            'edit' => EditSlot::route('/{record}/edit'),
        ];
    }
}
