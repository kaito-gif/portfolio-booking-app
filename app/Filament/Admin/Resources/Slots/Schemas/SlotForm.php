<?php

namespace App\Filament\Admin\Resources\Slots\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SlotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('workshop_id')
                    ->label('講座')
                    ->relationship('workshop', 'name')
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label('開催日時')
                    ->required(),
                TextInput::make('capacity')
                    ->label('定員')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('shopify_variant_id')
                    ->label('Shopify バリアントID')
                    ->maxLength(64),
                TextInput::make('note')
                    ->label('メモ')
                    ->maxLength(255),
            ]);
    }
}
