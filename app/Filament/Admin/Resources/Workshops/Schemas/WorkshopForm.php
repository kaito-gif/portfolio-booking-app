<?php

namespace App\Filament\Admin\Resources\Workshops\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WorkshopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('講座名')
                    ->required()
                    ->maxLength(100),
                Textarea::make('description')
                    ->label('説明')
                    ->columnSpanFull(),
                TextInput::make('duration_minutes')
                    ->label('所要時間（分）')
                    ->required()
                    ->numeric()
                    ->minValue(1),
                TextInput::make('shopify_product_id')
                    ->label('Shopify 商品ID')
                    ->maxLength(64),
                Toggle::make('is_active')
                    ->label('有効')
                    ->default(true)
                    ->required(),
            ]);
    }
}
