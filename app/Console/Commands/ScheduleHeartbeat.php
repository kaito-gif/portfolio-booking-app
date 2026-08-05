<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 詳細設計13章・9.1。/health が読む schedule.last_run_at を毎分書き込む。
 */
class ScheduleHeartbeat extends Command
{
    protected $signature = 'schedule:heartbeat';

    protected $description = 'スケジューラの最終実行時刻をcacheへ記録する';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        Cache::forever('schedule.last_run_at', $now->toIso8601String());

        $this->info("schedule:heartbeat recorded_at={$now->toIso8601String()}");

        return self::SUCCESS;
    }
}
