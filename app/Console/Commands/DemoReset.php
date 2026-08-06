<?php

namespace App\Console\Commands;

use App\Actions\CreateReservation;
use App\Actions\CreateReservationData;
use App\Contracts\InventoryServiceContract;
use App\Enums\ReservationSource;
use App\Enums\SlotStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計15.1。何度流しても同じ結果になる（冪等）。
 * このシステムは本番URL自体がデモ環境であり、APP_ENV=production の cron から
 * 毎日3:00に実行される想定（要件7.4・非機能要件7.4）。
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--dry-run : 実際には変更せず対象件数だけ表示する}';

    protected $description = 'デモデータを初期状態に戻す';

    /** @var string[] 見本の氏名。実在の個人情報と誤認されない架空の組み合わせ（要件7.2・15.2） */
    private const SAMPLE_NAMES = ['見本 太郎', '試用 花子', '見本 次郎', '試用 三郎'];

    public function handle(InventoryServiceContract $inventoryService): int
    {
        if ($this->option('dry-run')) {
            $this->info('demo:reset dry_run=1（truncate: reservations, slots, workshops, users / failed_jobs削除 / シード再投入 / Shopify在庫上書き）');

            return self::SUCCESS;
        }

        // MySQLのTRUNCATEは行が空でもFK制約の存在自体で失敗するため、一時的に無効化する。
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('reservations')->truncate();
        DB::table('slots')->truncate();
        DB::table('workshops')->truncate();
        DB::table('users')->truncate();
        DB::table('failed_jobs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $workshops = $this->seedWorkshops();
        $slots = $this->seedSlots($workshops, $inventoryService);
        $this->seedReservations($slots);
        $this->applyStatusMix($slots);
        $this->seedUsers();
        $this->resetInventory($slots, $inventoryService);

        $this->info('demo:reset processed workshops='.count($workshops).' slots='.count($slots));
        Log::info('demo:reset', ['workshops' => count($workshops), 'slots' => count($slots)]);

        return self::SUCCESS;
    }

    /** @return array<int, Workshop> */
    private function seedWorkshops(): array
    {
        $names = ['木工ワークショップ', '陶芸ワークショップ', 'アロマワークショップ'];

        return array_map(
            fn (string $name) => Workshop::create([
                'name' => $name,
                'description' => "{$name}の体験教室です。",
                'duration_minutes' => 90,
                'is_active' => true,
            ]),
            $names,
        );
    }

    /**
     * @param  array<int, Workshop>  $workshops
     * @return array<int, Slot>
     */
    private function seedSlots(array $workshops, InventoryServiceContract $inventoryService): array
    {
        $variantIds = config('booking.demo_slot_variant_ids');
        $slots = [];
        $index = 0;

        for ($dayOffset = 0; $dayOffset <= 14; $dayOffset++) {
            $slotsPerDay = $dayOffset % 2 === 0 ? 1 : 2;

            for ($i = 0; $i < $slotsPerDay; $i++) {
                $workshop = $workshops[array_rand($workshops)];
                // shopify_variant_id はslots側でユニーク制約があるため使い回せない。
                // 設定されたバリアントIDの数だけ枠を実接続し、足りない分はdraftのまま残す。
                $variantId = $variantIds[$index] ?? null;
                $startsAt = CarbonImmutable::now()->addDays($dayOffset)->setTime($i === 0 ? 10 : 15, 0);

                $slot = Slot::create([
                    'workshop_id' => $workshop->id,
                    'starts_at' => $startsAt,
                    'capacity' => 5,
                    'shopify_variant_id' => $variantId,
                ]);

                if ($variantId !== null) {
                    $slot->shopify_inventory_item_id = $inventoryService->resolveInventoryItemId($variantId);
                    $slot->save();
                    $slot->open();
                }

                $slots[] = $slot;
                $index++;
            }
        }

        return $slots;
    }

    /**
     * 状態遷移を見せるため、closed/completed/cancelledを1つずつ混ぜる（15.1）。
     * 予約を確定できるのはopenの枠だけ（isBookable）のため、必ず予約シード後に呼ぶ。
     * completedは終了日時が過去である必要があるため、通常の遷移メソッドでは今日以降の
     * 枠から作れない。デモ表示専用の初期状態としてここでのみ直接属性を設定する
     * （通常の業務経路では status への直接代入を禁止している）。
     *
     * @param  array<int, Slot>  $slots
     */
    private function applyStatusMix(array $slots): void
    {
        if (isset($slots[2]) && $slots[2]->status === SlotStatus::Open) {
            $slots[2]->status = SlotStatus::Closed;
            $slots[2]->save();
        }

        if (isset($slots[4]) && $slots[4]->status === SlotStatus::Open) {
            $slots[4]->status = SlotStatus::Cancelled;
            $slots[4]->save();
        }

        if (isset($slots[6]) && $slots[6]->status === SlotStatus::Open) {
            $slots[6]->status = SlotStatus::Completed;
            $slots[6]->save();
        }
    }

    /** @param  array<int, Slot>  $slots */
    private function seedReservations(array $slots): void
    {
        foreach ($slots as $slot) {
            // isBookable（status=open）でない枠には予約を確定できない。
            // Shopifyバリアント未設定（ローカルでBOOKING_DEMO_SLOT_VARIANT_IDS未設定）の
            // ときは全枠がdraftのままのため、ここで静かにスキップする。
            if (! $slot->isBookable()) {
                continue;
            }

            $count = random_int(0, $slot->capacity - 1);

            for ($seat = 1; $seat <= $count; $seat++) {
                $name = self::SAMPLE_NAMES[array_rand(self::SAMPLE_NAMES)];
                $email = 'sample+'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT).'@example.com';
                $phone = '090-0000-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                app(CreateReservation::class)->execute(new CreateReservationData(
                    slot: $slot,
                    name: $name,
                    email: $email,
                    phone: $phone,
                    source: ReservationSource::Seed,
                    reserveInventory: false,
                    sendMail: false,
                ));
            }
        }
    }

    private function seedUsers(): void
    {
        // is_demo はマスアサインメント対象外（管理画面フォームからの誤変更を防ぐ設計）。
        // ここではシード専用の初期化のため、作成後に直接プロパティへ設定する。
        $admin = User::create([
            'name' => '見本 管理者',
            'email' => 'demo-admin@example.com',
            'password' => Hash::make(config('booking.demo_password')),
            'role' => UserRole::Admin,
        ]);
        $admin->is_demo = true;
        $admin->save();

        $staff = User::create([
            'name' => '見本 スタッフ',
            'email' => 'demo-staff@example.com',
            'password' => Hash::make(config('booking.demo_password')),
            'role' => UserRole::Staff,
        ]);
        $staff->is_demo = true;
        $staff->save();
    }

    /** @param  array<int, Slot>  $slots */
    private function resetInventory(array $slots, InventoryServiceContract $inventoryService): void
    {
        foreach ($slots as $slot) {
            if ($slot->shopify_inventory_item_id === null) {
                continue;
            }

            $before = $slot->capacity;
            $after = max(0, $slot->capacity - $slot->confirmedCount());

            $inventoryService->set($slot, $after);

            AuditLog::record(
                action: 'inventory.reset',
                actorLabel: 'system:demo:reset',
                auditableType: 'Slot',
                auditableId: $slot->id,
                changes: ['before' => $before, 'after' => $after, 'slot_id' => $slot->id],
            );
        }
    }
}
