<?php

namespace App\Jobs;

use App\Contracts\InventoryServiceContract;
use App\Models\AuditLog;
use App\Models\Slot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 詳細設計8.3。冪等でないため、成功したジョブを再実行できる導線は作らない
 * （手動再実行は failed_jobs からのみ）。
 */
class AdjustShopifyInventory implements ShouldQueue
{
    use Queueable;

    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600];

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly int $slotId,
        public readonly int $delta,
        public readonly string $reason,
        public readonly ?int $reservationId = null,
    ) {
        $this->onQueue('priority');
    }

    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(InventoryServiceContract $inventoryService): void
    {
        $slot = Slot::find($this->slotId);

        if ($slot === null) {
            // demo:reset 等で行が消えた後の再実行（8.1の共通の作法）
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

    /** 最終失敗時。通知(14章)は段階5で実装するため、ここでは監査ログのみ残す。 */
    public function failed(Throwable $e): void
    {
        AuditLog::record(
            action: 'inventory.adjustment_failed',
            actorLabel: 'system:'.$this->reason,
            auditableType: 'Slot',
            auditableId: $this->slotId,
            changes: [
                'delta' => $this->delta,
                'reservation_id' => $this->reservationId,
                'error' => $e->getMessage(),
            ],
        );
    }
}
