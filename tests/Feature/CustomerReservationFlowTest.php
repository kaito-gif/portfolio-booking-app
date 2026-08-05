<?php

namespace Tests\Feature;

use App\Actions\CreateReservation;
use App\Actions\CreateReservationData;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\InventoryUnavailableException;
use App\Jobs\AdjustShopifyInventory;
use App\Models\Reservation;
use App\Models\Slot;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class CustomerReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-01 12:00:00');

        // 実APIを叩かないための保険（CLAUDE.md）。Http::fake()のstubは先勝ちで
        // 解決されるため、ここでは登録しない。各テストが必要な応答を個別にfakeする。
    }

    private function openSlot(?string $startsAt = null): Slot
    {
        $slot = Slot::create([
            'workshop_id' => Workshop::factory()->create()->id,
            'starts_at' => $startsAt ?? CarbonImmutable::now()->addDays(10),
            'capacity' => 5,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
            'shopify_inventory_item_id' => 'inv-1',
        ]);
        $slot->open();

        return $slot;
    }

    private function confirmedReservation(Slot $slot): Reservation
    {
        return app(CreateReservation::class)->execute(new CreateReservationData(
            slot: $slot,
            name: '見本 太郎',
            email: 'sample+01@example.com',
            phone: '090-0000-0001',
            source: ReservationSource::Manual,
            reserveInventory: false,
            sendMail: false,
        ));
    }

    private function successfulInventoryResponse(): array
    {
        return [
            'data' => [
                'inventoryAdjustQuantities' => [
                    'inventoryAdjustmentGroup' => [
                        'changes' => [['name' => 'available', 'delta' => -1, 'quantityAfterChange' => 4]],
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * 詳細設計16.1 #5: 在庫確保に失敗したら予約を残さない。
     */
    public function test_manual_registration_rolls_back_on_inventory_failure(): void
    {
        Http::fake(['*/graphql.json' => Http::response(['errors' => [['message' => 'boom']]], 500)]);

        $slot = $this->openSlot();

        $this->expectException(InventoryUnavailableException::class);

        try {
            app(CreateReservation::class)->execute(new CreateReservationData(
                slot: $slot,
                name: '見本 太郎',
                email: 'sample+01@example.com',
                phone: '090-0000-0001',
                source: ReservationSource::Manual,
                reserveInventory: true,
            ));
        } finally {
            $this->assertSame(0, Reservation::query()->count());
        }
    }

    /**
     * 詳細設計16.1 #6: 予約番号ごとの照会レート制限は5回で6回目が429になる。
     */
    public function test_lookup_rate_limit_blocks_after_five_attempts(): void
    {
        Http::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('lookup.submit'), [
                'code' => 'CHK-AAAAA-BBBBB',
                'email' => 'nope@example.com',
            ])->assertStatus(302);
        }

        $this->post(route('lookup.submit'), [
            'code' => 'CHK-AAAAA-BBBBB',
            'email' => 'nope@example.com',
        ])->assertStatus(429);
    }

    /**
     * 詳細設計16.1 #8: 在庫確保後にDB保存が失敗したら補償ジョブ(+1)を積む。
     */
    public function test_db_failure_after_inventory_decrement_queues_compensation(): void
    {
        Http::fake(['*/graphql.json' => Http::response($this->successfulInventoryResponse())]);
        Queue::fake();

        Reservation::saving(function (Reservation $reservation) {
            if ($reservation->status === ReservationStatus::Confirmed && $reservation->isDirty('status')) {
                throw new RuntimeException('simulated db failure');
            }
        });

        $slot = $this->openSlot();

        try {
            app(CreateReservation::class)->execute(new CreateReservationData(
                slot: $slot,
                name: '見本 太郎',
                email: 'sample+01@example.com',
                phone: '090-0000-0001',
                source: ReservationSource::Manual,
                reserveInventory: true,
            ));
            $this->fail('例外が発生するはずでした');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated db failure', $e->getMessage());
        }

        Queue::assertPushed(
            AdjustShopifyInventory::class,
            fn (AdjustShopifyInventory $job) => $job->slotId === $slot->id
                && $job->delta === 1
                && $job->reason === 'compensation'
        );
    }

    /**
     * 詳細設計16.1 #10: キャンセル期限の境界（前日23:59:59 と 翌0:00:00）。
     */
    public function test_customer_cannot_cancel_after_deadline(): void
    {
        Http::fake(['*/graphql.json' => Http::response($this->successfulInventoryResponse())]);

        $slot = $this->openSlot('2026-08-11 15:00:00');
        $reservation = $this->confirmedReservation($slot);

        CarbonImmutable::setTestNow('2026-08-10 23:59:59');
        $cancelUrl = URL::temporarySignedRoute('reservation.cancel', now()->addMinutes(30), ['reservation' => $reservation->id]);
        $this->post($cancelUrl)->assertRedirect();
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
    }

    public function test_customer_cannot_cancel_after_deadline_boundary_next_day(): void
    {
        Http::fake();

        $slot = $this->openSlot('2026-08-11 15:00:00');
        $reservation = $this->confirmedReservation($slot);

        CarbonImmutable::setTestNow('2026-08-11 00:00:00');
        $cancelUrl = URL::temporarySignedRoute('reservation.cancel', now()->addMinutes(30), ['reservation' => $reservation->id]);
        $response = $this->post($cancelUrl);
        $response->assertRedirect();
        $response->assertSessionHasErrors(['cancel']);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    /**
     * 詳細設計16.1 #11: 存在しない予約番号とメール不一致とで応答（文言）が同一。
     */
    public function test_lookup_response_is_identical_for_unknown_and_mismatched(): void
    {
        Http::fake();

        $reservation = $this->confirmedReservation($this->openSlot());

        $unknown = $this->post(route('lookup.submit'), [
            'code' => 'CHK-ZZZZZ-99999',
            'email' => 'someone@example.com',
        ]);
        $mismatched = $this->post(route('lookup.submit'), [
            'code' => $reservation->code,
            'email' => 'wrong@example.com',
        ]);

        $expectedMessage = '予約番号またはメールアドレスが一致しません';
        $unknown->assertSessionHasErrors(['code' => $expectedMessage]);
        $mismatched->assertSessionHasErrors(['code' => $expectedMessage]);
    }
}
