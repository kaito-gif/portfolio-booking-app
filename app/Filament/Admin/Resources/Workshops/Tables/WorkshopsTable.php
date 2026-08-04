<?php

namespace App\Filament\Admin\Resources\Workshops\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkshopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('講座名')
                    ->searchable(),
                TextColumn::make('duration_minutes')
                    ->label('所要時間（分）')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('shopify_product_id')
                    ->label('Shopify 商品ID')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
