<?php

namespace Tests\Feature;

use App\Enums\WebhookStatus;
use App\Models\Reservation;
use App\Models\Slot;
use App\Models\WebhookEvent;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // このテスト群は Shopify の在庫 API を呼ばない経路のみを扱うが、
        // 万一呼ばれた場合に実 API を叩かないための保険（CLAUDE.md）。
        Http::fake();
    }

    private function openSlot(string $variantId, ?Workshop $workshop = null): Slot
    {
        $slot = Slot::create([
            'workshop_id' => ($workshop ?? Workshop::factory()->create())->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 5,
            'shopify_variant_id' => $variantId,
            'shopify_inventory_item_id' => 'inv-'.$variantId,
        ]);
        $slot->open();

        return $slot;
    }

    /** @param  array<string, mixed>  $payload */
    private function postWebhook(
        array $payload,
        string $webhookId = 'wh-1',
        string $topic = 'orders/create',
        ?string $signatureOverride = null,
    ): TestResponse {
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $signature = $signatureOverride ?? base64_encode(
            hash_hmac('sha256', $rawBody, config('services.shopify.webhook_secret'), true)
        );

        return $this->call('POST', '/webhooks/shopify/orders-create', server: [
            'HTTP_X_Shopify_Hmac_Sha256' => $signature,
            'HTTP_X_Shopify_Webhook_Id' => $webhookId,
            'HTTP_X_Shopify_Topic' => $topic,
            'CONTENT_TYPE' => 'application/json',
        ], content: $rawBody);
    }

    /** @return array<string, mixed> */
    private function orderPayload(int $orderId, array $lineItems): array
    {
        return [
            'id' => $orderId,
            'email' => 'customer@example.com',
            'customer' => ['first_name' => '太郎', 'last_name' => '山田', 'phone' => '09011112222'],
            'line_items' => $lineItems,
        ];
    }

    public function test_webhook_invalid_hmac_returns_401(): void
    {
        $payload = $this->orderPayload(1001, []);

        $response = $this->postWebhook($payload, signatureOverride: 'invalid-signature');

        $response->assertStatus(401);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_duplicate_webhook_creates_single_reservation(): void
    {
        $slot = $this->openSlot('variant-1');
        $payload = $this->orderPayload(2001, [
            ['id' => 5001, 'variant_id' => 'variant-1', 'quantity' => 1],
        ]);

        $this->postWebhook($payload, webhookId: 'wh-dup')->assertStatus(200);
        $this->postWebhook($payload, webhookId: 'wh-dup')->assertStatus(200);

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, Reservation::where('slot_id', $slot->id)->count());
    }

    public function test_same_order_with_different_webhook_id_is_idempotent(): void
    {
        $slot = $this->openSlot('variant-1');
        $payload = $this->orderPayload(2002, [
            ['id' => 5002, 'variant_id' => 'variant-1', 'quantity' => 1],
        ]);

        $this->postWebhook($payload, webhookId: 'wh-a')->assertStatus(200);
        $this->postWebhook($payload, webhookId: 'wh-b')->assertStatus(200);

        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(1, Reservation::where('slot_id', $slot->id)->count());
    }

    public function test_quantity_two_creates_two_reservations(): void
    {
        $slot = $this->openSlot('variant-1');
        $payload = $this->orderPayload(2003, [
            ['id' => 5003, 'variant_id' => 'variant-1', 'quantity' => 2],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $reservations = Reservation::where('slot_id', $slot->id)->orderBy('seat_index')->get();
        $this->assertSame(2, $reservations->count());
        $this->assertSame([1, 2], $reservations->pluck('seat_index')->all());

        $event = WebhookEvent::sole();
        $this->assertTrue($event->status === WebhookStatus::Processed);
    }

    public function test_closed_slot_order_is_skipped_with_reason(): void
    {
        $slot = $this->openSlot('variant-1');
        $slot->close();

        $payload = $this->orderPayload(2004, [
            ['id' => 5004, 'variant_id' => 'variant-1', 'quantity' => 1],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(0, Reservation::count());

        $event = WebhookEvent::sole();
        $this->assertTrue($event->status === WebhookStatus::Skipped);
        $this->assertNotNull($event->failure_reason);
    }

    public function test_mixed_order_creates_reservations_only_for_slots(): void
    {
        $slot = $this->openSlot('variant-1');
        $payload = $this->orderPayload(2005, [
            ['id' => 5005, 'variant_id' => 'variant-1', 'quantity' => 1],
            ['id' => 5006, 'variant_id' => 'variant-goods-not-a-slot', 'quantity' => 1],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, Reservation::count());

        $event = WebhookEvent::sole();
        $this->assertTrue($event->status === WebhookStatus::Processed);
        $this->assertNotNull($event->failure_reason);
    }

    public function test_webhook_fails_when_line_item_id_missing(): void
    {
        $this->openSlot('variant-1');
        $payload = $this->orderPayload(2006, [
            ['variant_id' => 'variant-1', 'quantity' => 1],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(0, Reservation::count());

        $event = WebhookEvent::sole();
        $this->assertTrue($event->status === WebhookStatus::Failed);
        $this->assertStringContainsString('line_item.id', (string) $event->failure_reason);
    }
}
