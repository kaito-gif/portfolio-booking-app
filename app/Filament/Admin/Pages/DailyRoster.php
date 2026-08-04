<?php

namespace App\Filament\Admin\Pages;

use App\Actions\CheckInReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\CheckInNotRevertibleException;
use App\Models\Reservation;
use App\Models\Slot;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * 詳細設計11.4。日付を選ぶとその日の開催枠を並べ、枠ごとに氏名・電話・予約番号・
 * チェックイン欄を出す。既定の対象日は今日だが、前日印刷のため未来日も選べる。
 */
class DailyRoster extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = '当日リスト';

    protected static ?string $title = '当日リスト';

    protected string $view = 'filament.admin.pages.daily-roster';

    public string $date = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    /** @return Collection<int, Slot> */
    public function getWorkshopSlots(): Collection
    {
        return Slot::query()
            ->with(['workshop', 'reservations' => fn ($query) => $query
                ->whereIn('status', [
                    ReservationStatus::Confirmed,
                    ReservationStatus::Attended,
                    ReservationStatus::NoShow,
                ])
                ->orderBy('seat_index')])
            ->whereDate('starts_at', $this->date)
            ->orderBy('starts_at')
            ->get();
    }

    public function checkIn(int $reservationId): void
    {
        $reservation = Reservation::query()->findOrFail($reservationId);

        app(CheckInReservation::class)->execute($reservation, Auth::user());
    }

    /** 誤チェックインの取り消し。詳細設計5.4により管理者のみ許可する。 */
    public function revertCheckIn(int $reservationId): void
    {
        $reservation = Reservation::query()->findOrFail($reservationId);

        try {
            app(CheckInReservation::class)->revert($reservation, Auth::user());
        } catch (CheckInNotRevertibleException $e) {
            Notification::make()
                ->danger()
                ->title('チェックインを取り消せません')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function isCurrentUserAdmin(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }
}
