<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Slot;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 詳細設計11.6。直近7日の開催枠と確定数/定員。満席を強調する。
 */
class UpcomingSlots extends TableWidget
{
    protected static ?string $heading = '直近7日の開催枠';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Slot::query()
                    ->with('workshop')
                    ->withCount('confirmedReservations')
                    ->whereBetween('starts_at', [CarbonImmutable::now(), CarbonImmutable::now()->addDays(7)])
            )
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('開催日時')
                    ->dateTime(),
                TextColumn::make('workshop.name')
                    ->label('講座'),
                TextColumn::make('fill_state')
                    ->label('確定数 / 定員')
                    ->state(fn (Slot $record) => "{$record->confirmed_reservations_count} / {$record->capacity}")
                    ->badge()
                    ->color(fn (Slot $record) => $record->confirmed_reservations_count >= $record->capacity ? 'danger' : 'gray'),
            ]);
    }
}
