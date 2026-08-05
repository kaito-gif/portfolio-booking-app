<?php

namespace App\Models;

use App\Enums\CancelledBy;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['slot_id', 'code', 'name', 'email', 'phone', 'source', 'shopify_order_id', 'shopify_line_item_id', 'seat_index'])]
class Reservation extends Model
{
    protected $attributes = [
        'status' => 'inventory_pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'source' => ReservationSource::class,
            'cancelled_by' => CancelledBy::class,
            'seat_index' => 'integer',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function isCancellableByCustomer(): bool
    {
        return $this->status === ReservationStatus::Confirmed
            && CarbonImmutable::now()->lessThanOrEqualTo($this->slot->cancelDeadline());
    }

    public function lookupUrl(): string
    {
        return route('lookup.form', ['code' => $this->code]);
    }

    public function confirm(): void
    {
        if ($this->status !== ReservationStatus::InventoryPending) {
            throw new InvalidStateTransition("Reservation#{$this->id}: {$this->status->value} から confirmed へは遷移できません");
        }

        $this->status = ReservationStatus::Confirmed;
        $this->save();
    }

    public function cancel(CancelledBy $by): void
    {
        if (! in_array($this->status, [ReservationStatus::InventoryPending, ReservationStatus::Confirmed], true)) {
            throw new InvalidStateTransition("Reservation#{$this->id}: {$this->status->value} から cancelled へは遷移できません");
        }

        $this->status = ReservationStatus::Cancelled;
        $this->cancelled_at = CarbonImmutable::now();
        $this->cancelled_by = $by;
        $this->save();
    }

    public function checkIn(): void
    {
        if ($this->status !== ReservationStatus::Confirmed) {
            throw new InvalidStateTransition("Reservation#{$this->id}: {$this->status->value} から attended へは遷移できません");
        }

        $this->status = ReservationStatus::Attended;
        $this->checked_in_at = CarbonImmutable::now();
        $this->save();
    }

    public function markNoShow(): void
    {
        if ($this->status !== ReservationStatus::Confirmed) {
            throw new InvalidStateTransition("Reservation#{$this->id}: {$this->status->value} から no_show へは遷移できません");
        }

        $this->status = ReservationStatus::NoShow;
        $this->save();
    }

    public function revertCheckIn(): void
    {
        if ($this->status !== ReservationStatus::Attended) {
            throw new InvalidStateTransition("Reservation#{$this->id}: {$this->status->value} から confirmed への取り消しはできません");
        }

        $this->status = ReservationStatus::Confirmed;
        $this->checked_in_at = null;
        $this->save();
    }
}
