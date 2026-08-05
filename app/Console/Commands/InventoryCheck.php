<?php

namespace App\Console\Commands;

use App\Contracts\InventoryServiceContract;
use App\Enums\SlotStatus;
use App\Models\Slot;
use App\Support\AdminNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章・11.6。Shopifyの実在庫と期待値（定員-確定予約数）の差分を検出し、
 * 結果をcacheへ保存する（ダッシュボードのInventoryDriftウィジェットが読む。
 * 画面表示のたびにShopifyを叩かないため）。
 */
class InventoryCheck extends Command
{
    public const CACHE_KEY = 'inventory:check:result';

    protected $signature = 'inventory:check';

    protected $description = 'Shopifyの在庫と期待値の差分を検出し、cacheへ保存する';

    public function handle(InventoryServiceContract $inventoryService): int
    {
        $slots = Slot::query()
            ->with('workshop')
            ->whereIn('status', [SlotStatus::Open, SlotStatus::Closed])
            ->whereNotNull('shopify_inventory_item_id')
            ->get();

        $available = $inventoryService->fetchAvailable($slots);

        $drifted = [];

        foreach ($slots as $slot) {
            if (! array_key_exists($slot->id, $available)) {
                continue;
            }

            $expected = max(0, $slot->capacity - $slot->confirmedCount());
            $actual = $available[$slot->id];

            if ($expected !== $actual) {
                $drifted[] = [
                    'slot_id' => $slot->id,
                    'workshop_name' => $slot->workshop->name,
                    'starts_at' => $slot->starts_at->toIso8601String(),
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        $checkedAt = CarbonImmutable::now();

        Cache::put(self::CACHE_KEY, [
            'checked_at' => $checkedAt->toIso8601String(),
            'drifted' => $drifted,
        ]);

        foreach ($drifted as $drift) {
            AdminNotifier::notify(
                suppressionKey: "drift:{$drift['slot_id']}",
                subject: '【chanoka】在庫差分を検出しました',
                bodyText: "Slot#{$drift['slot_id']}（{$drift['workshop_name']}）で在庫差分を検出しました。期待値={$drift['expected']}、Shopify実値={$drift['actual']}",
                adminUrl: url("/admin/slots/{$drift['slot_id']}/edit"),
            );
        }

        $count = count($drifted);
        $this->info("inventory:check processed={$count}");
        Log::info('inventory:check', ['processed' => $count]);

        return self::SUCCESS;
    }
}
