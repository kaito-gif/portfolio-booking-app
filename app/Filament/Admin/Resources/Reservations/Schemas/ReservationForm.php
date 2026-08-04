<?php

namespace App\Filament\Admin\Resources\Reservations\Schemas;

use App\Enums\SlotStatus;
use App\Models\Slot;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('slot_id')
                    ->label('開催枠')
                    ->options(fn () => Slot::query()
                        ->where('status', SlotStatus::Open)
                        ->with('workshop')
                        ->get()
                        ->mapWithKeys(fn (Slot $slot) => [
                            $slot->id => "{$slot->workshop->name}｜{$slot->starts_at->format('Y-m-d H:i')}",
                        ]))
                    ->required()
                    ->searchable(),
                TextInput::make('name')
                    ->label('氏名')
                    ->required()
                    ->maxLength(50),
                TextInput::make('email')
                    ->label('メールアドレス')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('電話番号')
                    ->required()
                    ->regex('/\A[0-9+\-]{10,20}\z/')
                    ->maxLength(20),
            ]);
    }
}
