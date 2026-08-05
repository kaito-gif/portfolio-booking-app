<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 詳細設計13.1。slots:complete と mark-no-show を0:30/0:35に分けるのは、
// 枠が completed になってから欠席判定を回すという順序依存があるため（設計9）。
Schedule::command('queue:work --queue=priority,default --stop-when-empty --max-time=50')
    ->everyMinute()->withoutOverlapping();
Schedule::command('schedule:heartbeat')->everyMinute();
Schedule::command('inventory:check')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('slots:close')->dailyAt('00:05');
Schedule::command('slots:complete')->dailyAt('00:30');
Schedule::command('reservations:mark-no-show')->dailyAt('00:35');
Schedule::command('demo:reset')->dailyAt('03:00');
Schedule::command('logs:prune')->dailyAt('03:30');
Schedule::command('reservations:remind')->dailyAt('07:00');
