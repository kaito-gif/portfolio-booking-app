<?php

namespace App\Services\Shopify;

use App\Contracts\InventoryServiceContract;
use App\Exceptions\ShopifyApiException;
use App\Models\Slot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 詳細設計7.2。段階2から InventoryServiceContract の実装として使う
 * （AppServiceProvider で FakeInventoryService から差し替え）。
 */
final class InventoryService implements InventoryServiceContract
{
    public function __construct(
        private readonly ShopifyClient $client,
        private readonly string $locationId,
    ) {}

    public function resolveInventoryItemId(string $variantId): string
    {
        $data = $this->client->graphql(
            <<<'GRAPHQL'
            query($id: ID!) {
              productVariant(id: $id) {
                inventoryItem { id }
              }
            }
            GRAPHQL,
            ['id' => $variantId],
        );

        $inventoryItemId = $data['productVariant']['inventoryItem']['id'] ?? null;

        if ($inventoryItemId === null) {
            throw new ShopifyApiException("バリアント {$variantId} の在庫アイテムを解決できませんでした");
        }

        return $inventoryItemId;
    }

    /**
     * 実クレデンシャルでの疎通確認で判明（詳細設計19章の未決事項）：
     * `inventoryAdjustQuantities`/`inventorySetQuantities` は実スキーマ
     * （2026-07時点）では `changeFromQuantity`（比較対象の現在値）を必須で要求し、
     * さらにフィールドへ `@idempotent(key: ...)` ディレクティブが無いと
     * 「ディレクティブが必要」エラーで拒否される。設計時点で想定していた
     * 「delta/絶対値だけを渡す薄いAPI」ではなくなっており、呼び出し前に現在値を
     * 読みに行く必要がある（読み取り1回+書き込み1回の2往復になる）。
     * 読み取りと書き込みの間に競合が起きた場合はuserErrorsとして失敗し、
     * ジョブの再試行（8.3のtries/backoff）に委ねる。
     *
     * `changes[].quantityAfterChange` はレスポンスの型上は存在するが、実際には
     * 常に `null` が返る（実クレデンシャルで確認済み。おそらく非同期処理のため
     * 即時反映されない）。userErrorsが空＝changeFromQuantityどおりdeltaが
     * 適用されたことが保証されるため、応答を信用せず `$current + $delta` を
     * 返す（再読み込みの往復を増やさないため）。
     */
    public function adjust(Slot $slot, int $delta, string $reason): int
    {
        $current = $this->currentAvailable($slot);

        $this->client->graphql(
            <<<'GRAPHQL'
            mutation($input: InventoryAdjustQuantitiesInput!, $key: String!) {
              inventoryAdjustQuantities(input: $input) @idempotent(key: $key) {
                userErrors { field message }
              }
            }
            GRAPHQL,
            [
                'input' => [
                    'reason' => 'correction',
                    'name' => 'available',
                    'changes' => [[
                        'inventoryItemId' => $slot->shopify_inventory_item_id,
                        'locationId' => $this->locationId,
                        'delta' => $delta,
                        'changeFromQuantity' => $current,
                    ]],
                ],
                'key' => (string) Str::uuid(),
            ],
        );

        return $current + $delta;
    }

    public function set(Slot $slot, int $quantity): void
    {
        $current = $this->currentAvailable($slot);

        $this->client->graphql(
            <<<'GRAPHQL'
            mutation($input: InventorySetQuantitiesInput!, $key: String!) {
              inventorySetQuantities(input: $input) @idempotent(key: $key) {
                userErrors { field message }
              }
            }
            GRAPHQL,
            [
                'input' => [
                    'name' => 'available',
                    'reason' => 'correction',
                    'quantities' => [[
                        'inventoryItemId' => $slot->shopify_inventory_item_id,
                        'locationId' => $this->locationId,
                        'quantity' => $quantity,
                        'changeFromQuantity' => $current,
                    ]],
                ],
                'key' => (string) Str::uuid(),
            ],
        );
    }

    private function currentAvailable(Slot $slot): int
    {
        return $this->fetchAvailable(collect([$slot]))[$slot->id] ?? 0;
    }

    public function fetchAvailable(Collection $slots): array
    {
        $result = [];

        foreach ($slots->chunk(50) as $chunk) {
            $ids = $chunk->pluck('shopify_inventory_item_id')->filter()->values()->all();

            if ($ids === []) {
                continue;
            }

            $data = $this->client->graphql(
                <<<'GRAPHQL'
                query($ids: [ID!]!, $locationId: ID!) {
                  nodes(ids: $ids) {
                    ... on InventoryItem {
                      id
                      inventoryLevel(locationId: $locationId) {
                        quantities(names: ["available"]) { quantity }
                      }
                    }
                  }
                }
                GRAPHQL,
                ['ids' => $ids, 'locationId' => $this->locationId],
            );

            $availableByInventoryItemId = collect($data['nodes'] ?? [])
                ->filter()
                ->mapWithKeys(fn (array $node) => [
                    $node['id'] => (int) ($node['inventoryLevel']['quantities'][0]['quantity'] ?? 0),
                ]);

            foreach ($chunk as $slot) {
                if ($slot->shopify_inventory_item_id !== null
                    && $availableByInventoryItemId->has($slot->shopify_inventory_item_id)) {
                    $result[$slot->id] = $availableByInventoryItemId[$slot->shopify_inventory_item_id];
                }
            }
        }

        return $result;
    }
}
