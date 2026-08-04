<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Slot;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function slot(): Slot
    {
        $workshop = Workshop::factory()->create();

        return Slot::create([
            'workshop_id' => $workshop->id,
            'starts_at' => now()->addDays(10),
            'capacity' => 5,
        ]);
    }

    public function test_staff_cannot_create_or_edit_workshops(): void
    {
        $staff = $this->staff();
        $workshop = $this->slot()->workshop;

        $this->actingAs($staff)->get('/admin/workshops')->assertOk();
        $this->actingAs($staff)->get('/admin/workshops/create')->assertForbidden();
        $this->actingAs($staff)->get("/admin/workshops/{$workshop->id}/edit")->assertForbidden();
    }

    public function test_staff_cannot_create_or_edit_slots(): void
    {
        $staff = $this->staff();
        $slot = $this->slot();

        $this->actingAs($staff)->get('/admin/slots')->assertOk();
        $this->actingAs($staff)->get('/admin/slots/create')->assertForbidden();
        $this->actingAs($staff)->get("/admin/slots/{$slot->id}/edit")->assertForbidden();
    }

    public function test_staff_can_view_and_create_reservations(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/admin/reservations')->assertOk();
        $this->actingAs($staff)->get('/admin/reservations/create')->assertOk();
    }

    public function test_admin_can_create_and_edit_workshops(): void
    {
        $admin = $this->admin();
        $workshop = $this->slot()->workshop;

        $this->actingAs($admin)->get('/admin/workshops/create')->assertOk();
        $this->actingAs($admin)->get("/admin/workshops/{$workshop->id}/edit")->assertOk();
    }

    public function test_admin_can_create_and_edit_slots(): void
    {
        $admin = $this->admin();
        $slot = $this->slot();

        $this->actingAs($admin)->get('/admin/slots/create')->assertOk();
        $this->actingAs($admin)->get("/admin/slots/{$slot->id}/edit")->assertOk();
    }
}
