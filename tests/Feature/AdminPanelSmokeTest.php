<?php

namespace Tests\Feature;

use App\Actions\CreateReservation;
use App\Actions\CreateReservationData;
use App\Enums\ReservationSource;
use App\Enums\UserRole;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_admin_can_view_workshop_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/workshops')->assertOk();
        $this->actingAs($admin)->get('/admin/workshops/create')->assertOk();
    }

    public function test_admin_can_view_slot_pages(): void
    {
        $admin = $this->admin();
        Workshop::factory()->create();

        $this->actingAs($admin)->get('/admin/slots')->assertOk();
        $this->actingAs($admin)->get('/admin/slots/create')->assertOk();
    }

    public function test_admin_can_view_reservation_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/reservations')->assertOk();
        $this->actingAs($admin)->get('/admin/reservations/create')->assertOk();
    }

    public function test_admin_can_view_webhook_events_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/webhook-events')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/reservations')->assertRedirect('/admin/login');
    }

    public function test_admin_can_view_populated_reservation_and_slot_lists(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'inventoryAdjustQuantities' => [
                        'inventoryAdjustmentGroup' => [
                            'changes' => [['name' => 'available', 'delta' => -1, 'quantityAfterChange' => 4]],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ]),
        ]);

        $admin = $this->admin();

        $workshop = Workshop::factory()->create();
        $slot = Slot::create([
            'workshop_id' => $workshop->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 5,
            'shopify_variant_id' => 'gid://test/1',
            'shopify_inventory_item_id' => 'gid://test/inv/1',
        ]);
        $slot->open();

        app(CreateReservation::class)->execute(new CreateReservationData(
            slot: $slot->fresh(),
            name: 'テスト太郎',
            email: 'test@example.com',
            phone: '09011112222',
            source: ReservationSource::Manual,
            reserveInventory: true,
            sendMail: false,
        ));

        $this->actingAs($admin)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('テスト太郎')
            ->assertSee('確定');

        $this->actingAs($admin)->get('/admin/slots')
            ->assertOk()
            ->assertSee('受付中');
    }
}
