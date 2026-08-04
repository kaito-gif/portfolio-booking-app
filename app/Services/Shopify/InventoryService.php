<?php

namespace App\Services\Shopify;

use App\Contracts\InventoryServiceContract;
use App\Exceptions\ShopifyApiException;
use App\Models\Slot;
use Illuminate\Support\Collection;

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

    public function adjust(Slot $slot, int $delta, string $reason): int
    {
        $data = $this->client->graphql(
            <<<'GRAPHQL'
            mutation($input: InventoryAdjustQuantitiesInput!) {
              inventoryAdjustQuantities(input: $input) {
                inventoryAdjustmentGroup {
                  changes { name delta quantityAfterChange }
                }
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
                    ]],
                ],
            ],
        );

        $changes = $data['inventoryAdjustQuantities']['inventoryAdjustmentGroup']['changes'] ?? [];

        return (int) ($changes[0]['quantityAfterChange'] ?? 0);
    }

    public function set(Slot $slot, int $quantity): void
    {
        $this->client->graphql(
            <<<'GRAPHQL'
            mutation($input: InventorySetQuantitiesInput!) {
              inventorySetQuantities(input: $input) {
                userErrors { field message }
              }
            }
            GRAPHQL,
            [
                'input' => [
                    'name' => 'available',
                    'reason' => 'correction',
                    'ignoreCompareQuantity' => true,
                    'quantities' => [[
                        'inventoryItemId' => $slot->shopify_inventory_item_id,
                        'locationId' => $this->locationId,
                        'quantity' => $quantity,
                    ]],
                ],
            ],
        );
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
