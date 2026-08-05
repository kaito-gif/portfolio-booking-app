<?php

namespace App\Console\Commands;

use App\Enums\SlotStatus;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章。前日23:59を過ぎた受付中の枠を締切にする。
 */
class SlotsClose extends Command
{
    protected $signature = 'slots:close';

    protected $description = '前日23:59を過ぎた受付中の開催枠を締切にする';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $count = 0;

        Slot::query()->where('status', SlotStatus::Open)->each(function (Slot $slot) use ($now, &$count) {
            if ($now->greaterThan($slot->cancelDeadline())) {
                $slot->close();
                $count++;
            }
        });

        $this->info("slots:close processed={$count}");
        Log::info('slots:close', ['processed' => $count]);

        return self::SUCCESS;
    }
}
