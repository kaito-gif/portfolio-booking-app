<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Reservations\Pages\CreateReservation;
use App\Models\Reservation;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 第三者レビュー指摘への対応: 公開デモの管理画面は共有パスワードで誰でも入れるため、
 * 手動予約登録(=実メール送信)を無制限に呼べると外部への送信踏み台になり得る。
 */
class ReservationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function openSlot(): Slot
    {
        $slot = Slot::create([
            'workshop_id' => Workshop::factory()->create()->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 100,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
            'shopify_inventory_item_id' => 'inv-1',
        ]);
        $slot->open();

        return $slot;
    }

    public function test_manual_reservation_creation_is_capped_per_day(): void
    {
        Http::fake();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $slot = $this->openSlot();

        for ($i = 0; $i < 10; $i++) {
            Livewire::test(CreateReservation::class)
                ->fillForm([
                    'slot_id' => $slot->id,
                    'name' => "見本 太郎{$i}",
                    'email' => "sample+{$i}@example.com",
                    'phone' => '090-0000-0001',
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        Livewire::test(CreateReservation::class)
            ->fillForm([
                'slot_id' => $slot->id,
                'name' => '見本 上限超え',
                'email' => 'sample+over@example.com',
                'phone' => '090-0000-0001',
            ])
            ->call('create');

        $this->assertSame(10, Reservation::count());
    }
}
