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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    // 手動登録は既定で確認メールを実送信するため、公開デモの共有アカウントが
    // 任意の外部アドレスへの送信踏み台にならないようユーザーID単位で日次上限を設ける。
    private const MAX_CREATIONS_PER_DAY = 10;

    protected function handleRecordCreation(array $data): Model
    {
        $limiterKey = 'admin-reservation-create:user:'.(Auth::id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_CREATIONS_PER_DAY)) {
            Notification::make()
                ->danger()
                ->title('本日の手動登録上限に達しました')
                ->body('確認メールの誤送信・悪用防止のため、1日あたりの手動登録回数に上限を設けています。日をまたいでから再度お試しください。')
                ->send();

            $this->halt();
        }

        RateLimiter::hit($limiterKey, 86400);

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
