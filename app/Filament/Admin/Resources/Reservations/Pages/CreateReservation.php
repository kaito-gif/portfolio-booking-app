<?php

namespace App\Filament\Admin\Resources\Reservations\Pages;

use App\Actions\CreateReservation as CreateReservationAction;
use App\Actions\CreateReservationData;
use App\Enums\ReservationSource;
use App\Exceptions\InventoryUnavailableException;
use App\Filament\Admin\Resources\Reservations\ReservationResource;
use App\Models\Slot;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $slot = Slot::findOrFail($data['slot_id']);

        try {
            return app(CreateReservationAction::class)->execute(new CreateReservationData(
                slot: $slot,
                name: $data['name'],
                email: $data['email'],
                phone: $data['phone'],
                source: ReservationSource::Manual,
                reserveInventory: true,
            ));
        } catch (InventoryUnavailableException) {
            Notification::make()
                ->danger()
                ->title('在庫を確保できませんでした')
                ->body('Shopify の在庫を確保できませんでした。予約は登録されていません')
                ->send();

            $this->halt();
        }
    }
}
