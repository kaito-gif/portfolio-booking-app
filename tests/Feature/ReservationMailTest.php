<?php

namespace Tests\Feature;

use App\Actions\CreateReservation;
use App\Actions\CreateReservationData;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Jobs\SendReservationMail;
use App\Models\MailLog;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ReservationMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    private function openSlot(): Slot
    {
        $slot = Slot::create([
            'workshop_id' => Workshop::factory()->create()->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 5,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
            'shopify_inventory_item_id' => 'inv-1',
        ]);
        $slot->open();

        return $slot;
    }

    public function test_confirmed_mail_is_sent_and_logged(): void
    {
        $slot = $this->openSlot();

        $reservation = app(CreateReservation::class)->execute(new CreateReservationData(
            slot: $slot,
            name: '見本 太郎',
            email: 'sample+01@example.com',
            phone: '090-0000-0001',
            source: ReservationSource::Manual,
            reserveInventory: false,
        ));

        $mailLog = MailLog::query()->where('reservation_id', $reservation->id)->sole();

        $this->assertSame('sent', $mailLog->status);
        $this->assertSame('confirmed', $mailLog->type);
        $this->assertSame('【chanoka】ワークショップのご予約を承りました', $mailLog->subject);
        $this->assertNotNull($mailLog->body);
        $this->assertNotNull($mailLog->sent_at);
    }

    /**
     * 詳細設計16.1 テスト#7: mail_failure_does_not_break_reservation。
     * メール送信が失敗しても予約は confirmed のまま残り、mail_logs.status=failed になる。
     */
    public function test_mail_failure_does_not_break_reservation(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('smtp down'));

        $slot = $this->openSlot();

        $reservation = app(CreateReservation::class)->execute(new CreateReservationData(
            slot: $slot,
            name: '見本 太郎',
            email: 'sample+01@example.com',
            phone: '090-0000-0001',
            source: ReservationSource::Manual,
            reserveInventory: false,
        ));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        $mailLog = MailLog::query()->where('reservation_id', $reservation->id)->sole();
        $this->assertSame('failed', $mailLog->status);
        $this->assertSame('smtp down', $mailLog->last_error);
    }

    public function test_resend_reuses_existing_mail_log_row(): void
    {
        $slot = $this->openSlot();

        $reservation = app(CreateReservation::class)->execute(new CreateReservationData(
            slot: $slot,
            name: '見本 太郎',
            email: 'sample+01@example.com',
            phone: '090-0000-0001',
            source: ReservationSource::Manual,
            reserveInventory: false,
            sendMail: false,
        ));

        $mailLog = MailLog::create([
            'reservation_id' => $reservation->id,
            'related_reservation_ids' => [$reservation->id],
            'type' => 'confirmed',
            'to' => $reservation->email,
            'subject' => '【chanoka】ワークショップのご予約を承りました',
            'status' => 'failed',
            'last_error' => 'previous failure',
        ]);

        SendReservationMail::dispatch('confirmed', [$reservation->id], $reservation->email, $mailLog->id);

        $this->assertSame(1, MailLog::query()->count());

        $mailLog->refresh();
        $this->assertSame('sent', $mailLog->status);
        $this->assertNull($mailLog->last_error);
    }

    public function test_admin_can_view_mail_logs_and_daily_roster_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/mail-logs')->assertOk();
        $this->actingAs($admin)->get('/admin/daily-roster')->assertOk();
    }
}
