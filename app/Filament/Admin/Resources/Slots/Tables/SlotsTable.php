<?php

namespace App\Filament\Admin\Resources\Slots\Tables;

use App\Enums\SlotStatus;
use App\Models\Slot;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label('開催日時')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('workshop.name')
                    ->label('講座'),
                TextColumn::make('capacity')
                    ->label('定員')
                    ->numeric(),
                TextColumn::make('confirmed_reservations_count')
                    ->label('確定数')
                    ->counts('confirmedReservations'),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (SlotStatus $state) => $state->label()),
                TextColumn::make('shopify_variant_id')
                    ->label('バリアントID'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('open')
                    ->label('受付中にする')
                    ->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (Slot $record) => $record->status === SlotStatus::Draft
                        && $record->shopify_inventory_item_id !== null)
                    ->requiresConfirmation()
                    ->action(fn (Slot $record) => $record->open()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
