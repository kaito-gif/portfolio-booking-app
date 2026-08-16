<?php

namespace Tests\Feature;

use App\Mail\AdminAlertMail;
use App\Models\AuditLog;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 詳細設計16.1 #13: demo_reset_is_idempotent_and_leaves_no_drift。
 */
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    private function fakeShopify(): void
    {
        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'productVariant')) {
                return Http::response([
                    'data' => ['productVariant' => ['inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1']]],
                ]);
            }

            if (str_contains($query, 'inventorySetQuantities')) {
                return Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => []]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    public function test_demo_reset_is_idempotent_and_leaves_no_drift(): void
    {
        $this->fakeShopify();

        Artisan::call('demo:reset');

        $workshopsFirst = Workshop::count();
        $slotsFirst = Slot::count();
        $usersFirst = User::count();
        $this->assertSame(3, $workshopsFirst);
        $this->assertGreaterThan(0, $slotsFirst);
        $this->assertSame(2, $usersFirst);

        // audit_logs は demo:reset をまたいで残る(15.1)ため、2回目の分だけを見る必要がある。
        // created_at(秒精度)は2回の実行が同一秒内に収まるとテスト環境では区別できない
        // ため、IDを基準にする。TRUNCATEでslotsのauto_incrementが振り出しに戻るため、
        // 1回目のログをそのまま混ぜて検証すると別の枠を指してしまう。
        $lastIdBeforeSecondRun = (int) (AuditLog::query()->max('id') ?? 0);
        Artisan::call('demo:reset');

        $this->assertSame($workshopsFirst, Workshop::count());
        $this->assertSame($slotsFirst, Slot::count());
        $this->assertSame($usersFirst, User::count());

        // 差分0: inventory.reset の後値が capacity - 確定予約数 と一致すること
        // （resetInventory が set() に渡す値そのものであり、ここではその整合性を再検証する）。
        $resetLogs = AuditLog::query()
            ->where('action', 'inventory.reset')
            ->where('id', '>', $lastIdBeforeSecondRun)
            ->get();
        $this->assertNotEmpty($resetLogs);

        foreach ($resetLogs as $log) {
            $slot = Slot::find($log->auditable_id);
            $this->assertNotNull($slot);
            $expected = max(0, $slot->capacity - $slot->confirmedCount());
            $this->assertSame($expected, $log->changes['after']);
        }
    }

    public function test_demo_reset_dry_run_does_not_modify_data(): void
    {
        $this->fakeShopify();
        Artisan::call('demo:reset');
        $slotsBefore = Slot::count();

        Artisan::call('demo:reset', ['--dry-run' => true]);

        $this->assertSame($slotsBefore, Slot::count());
    }

    public function test_demo_reset_runs_in_production(): void
    {
        $this->fakeShopify();
        app()['env'] = 'production';

        Artisan::call('demo:reset');

        $this->assertGreaterThan(0, Workshop::count());
        $this->assertSame(2, User::where('is_demo', true)->count());

        app()['env'] = 'testing';
    }

    /**
     * 第三者レビュー指摘への対応: demo:reset の失敗は schedule:heartbeat とは
     * 独立しているため /health からは見えない。失敗時に管理者へ通知することを保証する。
     */
    public function test_demo_reset_failure_notifies_admin_and_returns_failure(): void
    {
        config(['booking.admin_notification_email' => 'admin@example.com']);
        Mail::fake();

        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'productVariant')) {
                return Http::response(['errors' => [['message' => 'boom']]], 500);
            }

            return Http::response(['data' => []]);
        });

        $exitCode = Artisan::call('demo:reset');

        $this->assertSame(1, $exitCode);
        Mail::assertSent(AdminAlertMail::class, fn (AdminAlertMail $mail) => $mail->hasTo('admin@example.com')
            && str_contains($mail->alertSubject, 'demo:reset'));
        $this->assertNull(Cache::get('demo_reset.last_success_at'));
    }

    public function test_demo_reset_records_last_success_at_on_success(): void
    {
        $this->fakeShopify();

        Artisan::call('demo:reset');

        $this->assertNotNull(Cache::get('demo_reset.last_success_at'));
    }
}
