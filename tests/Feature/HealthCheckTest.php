<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_ok_when_no_heartbeat_recorded_yet(): void
    {
        Cache::forget('schedule.last_run_at');

        $this->get('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'schedule_last_run_at' => null,
                'lag_seconds' => null,
            ]);
    }

    public function test_returns_ok_when_heartbeat_is_recent(): void
    {
        Cache::put('schedule.last_run_at', now());

        $response = $this->get('/health')->assertOk();

        $this->assertSame('ok', $response->json('status'));
        $this->assertLessThan(5, $response->json('lag_seconds'));
    }

    public function test_returns_stale_when_heartbeat_is_too_old(): void
    {
        Cache::put('schedule.last_run_at', now()->subSeconds(700));

        $this->get('/health')
            ->assertStatus(503)
            ->assertJson(['status' => 'stale']);
    }
}
