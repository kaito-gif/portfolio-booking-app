<?php

namespace App\Filament\Admin\Resources\Reservations;

use App\Filament\Admin\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Admin\Resources\Reservations\Pages\EditReservation;
use App\Filament\Admin\Resources\Reservations\Pages\ListReservations;
use App\Filament\Admin\Resources\Reservations\Schemas\ReservationForm;
use App\Filament\Admin\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = '予約';

    protected static ?string $modelLabel = '予約';

    public static function form(Schema $schema): Schema
    {
        return ReservationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
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
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'edit' => EditReservation::route('/{record}/edit'),
        ];
    }
}
