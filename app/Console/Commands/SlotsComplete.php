<?php

namespace App\Console\Commands;

use App\Enums\SlotStatus;
use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章。開催終了日時を過ぎた締切枠を開催済みにする。
 */
class SlotsComplete extends Command
{
    protected $signature = 'slots:complete';

    protected $description = '開催終了日時を過ぎた締切中の開催枠を開催済みにする';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $count = 0;

        Slot::query()->with('workshop')->where('status', SlotStatus::Closed)->each(function (Slot $slot) use ($now, &$count) {
            if ($now->greaterThanOrEqualTo($slot->endsAt())) {
                $slot->complete();
                $count++;
            }
        });

        $this->info("slots:complete processed={$count}");
        Log::info('slots:complete', ['processed' => $count]);

        return self::SUCCESS;
    }
}
