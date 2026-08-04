<?php

namespace Tests\Feature;

use App\Actions\CheckInReservation;
use App\Actions\CreateReservation;
use App\Actions\CreateReservationData;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Exceptions\CheckInNotRevertibleException;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckInReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    private function confirmedReservation()
    {
        $slot = Slot::create([
            'workshop_id' => Workshop::factory()->create()->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 5,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
            'shopify_inventory_item_id' => 'inv-1',
        ]);
        $slot->open();

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

    public function test_checked_in_reservation_can_be_reverted_by_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $reservation = $this->confirmedReservation();

        app(CheckInReservation::class)->execute($reservation, $admin);
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Attended, $reservation->status);

        app(CheckInReservation::class)->revert($reservation, $admin);
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->checked_in_at);
    }

    /**
     * 詳細設計5.4: チェックインの取り消しは管理者のみに許可する。
     */
    public function test_staff_cannot_revert_check_in(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $reservation = $this->confirmedReservation();

        app(CheckInReservation::class)->execute($reservation, $staff);
        $reservation->refresh();

        $this->expectException(CheckInNotRevertibleException::class);
        app(CheckInReservation::class)->revert($reservation, $staff);
    }
}
