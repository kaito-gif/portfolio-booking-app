<?php

namespace App\Jobs;

use App\Contracts\InventoryServiceContract;
use App\Models\AuditLog;
use App\Models\Slot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdjustShopifyInventory implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $slotId,
        public readonly int $delta,
        public readonly string $reason,
        public readonly ?int $reservationId = null,
    ) {}

    public function handle(InventoryServiceContract $inventoryService): void
    {
        $slot = Slot::find($this->slotId);

        if ($slot === null) {
            return;
        }

        $before = max(0, $slot->capacity - $slot->confirmedCount());
        $after = $inventoryService->adjust($slot, $this->delta, $this->reason);

        AuditLog::record(
            action: $this->delta >= 0 ? 'inventory.incremented' : 'inventory.decremented',
            actorLabel: 'system:'.$this->reason,
            auditableType: 'Slot',
            auditableId: $slot->id,
            changes: ['before' => $before, 'after' => $after, 'slot_id' => $slot->id],
        );
    }
}
