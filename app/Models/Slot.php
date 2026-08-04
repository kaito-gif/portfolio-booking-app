<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workshop_id', 'starts_at', 'capacity', 'shopify_variant_id', 'shopify_inventory_item_id', 'note'])]
class Slot extends Model
{
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'capacity' => 'integer',
            'status' => SlotStatus::class,
        ];
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** 一覧の確定数集計用（`withCount` で N+1 を避ける。詳細設計11.3） */
    public function confirmedReservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->whereIn('status', [
            ReservationStatus::Confirmed,
            ReservationStatus::Attended,
            ReservationStatus::NoShow,
        ]);
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->starts_at)
            ->addMinutes($this->workshop->duration_minutes);
    }

    public function confirmedCount(): int
    {
        return $this->reservations()
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::Attended,
                ReservationStatus::NoShow,
            ])
            ->count();
    }

    public function isBookable(): bool
    {
        return $this->status === SlotStatus::Open;
    }

    public function cancelDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->starts_at)
            ->subDay()
            ->endOfDay();
    }

    public function open(): void
    {
        if ($this->status !== SlotStatus::Draft) {
            throw new InvalidStateTransition("Slot#{$this->id}: {$this->status->value} から open へは遷移できません");
        }

        if ($this->shopify_variant_id === null || $this->shopify_inventory_item_id === null) {
            throw new InvalidStateTransition("Slot#{$this->id}: Shopify バリアント・在庫アイテムが未解決のため open にできません");
        }

        $this->status = SlotStatus::Open;
        $this->save();
    }

    public function close(): void
    {
        if ($this->status !== SlotStatus::Open) {
            throw new InvalidStateTransition("Slot#{$this->id}: {$this->status->value} から closed へは遷移できません");
        }

        $this->status = SlotStatus::Closed;
        $this->save();
    }

    public function cancel(): void
    {
        if (! in_array($this->status, [SlotStatus::Draft, SlotStatus::Open, SlotStatus::Closed], true)) {
            throw new InvalidStateTransition("Slot#{$this->id}: {$this->status->value} から cancelled へは遷移できません");
        }

        $this->status = SlotStatus::Cancelled;
        $this->save();
    }

    public function complete(): void
    {
        if ($this->status !== SlotStatus::Closed) {
            throw new InvalidStateTransition("Slot#{$this->id}: {$this->status->value} から completed へは遷移できません");
        }

        if (CarbonImmutable::now()->lessThan($this->endsAt())) {
            throw new InvalidStateTransition("Slot#{$this->id}: 開催終了日時を過ぎていません");
        }

        $this->status = SlotStatus::Completed;
        $this->save();
    }
}
