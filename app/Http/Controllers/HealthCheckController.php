<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckController extends Controller
{
    private const STALE_THRESHOLD_SECONDS = 600;

    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response()->json(['status' => 'error'], 503);
        }

        $lastRunAt = Cache::get('schedule.last_run_at');

        if ($lastRunAt === null) {
            // schedule.last_run_at を書き込むバッチコマンドが未実装のため、
            // 現時点では「まだハートビートが無い」= ok として扱う（docs/context.md 参照）。
            return response()->json([
                'status' => 'ok',
                'schedule_last_run_at' => null,
                'lag_seconds' => null,
            ]);
        }

        $lastRunAt = CarbonImmutable::parse($lastRunAt);
        $lagSeconds = (int) CarbonImmutable::now()->diffInSeconds($lastRunAt, absolute: true);

        $isStale = $lagSeconds > self::STALE_THRESHOLD_SECONDS;

        return response()->json([
            'status' => $isStale ? 'stale' : 'ok',
            'schedule_last_run_at' => $lastRunAt->toIso8601String(),
            'lag_seconds' => $lagSeconds,
        ], $isStale ? 503 : 200);
    }
}
