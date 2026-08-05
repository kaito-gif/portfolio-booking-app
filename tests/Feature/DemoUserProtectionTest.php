<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 詳細設計16.1 #12: demo_user_cannot_be_deleted_or_role_changed。
 * Policyと URL 直叩きの両方で塞がれていることを確認する。
 */
class DemoUserProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function demoUser(): User
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);
        $user->is_demo = true;
        $user->save();

        return $user;
    }

    public function test_policy_denies_update_and_delete_for_demo_user(): void
    {
        $admin = $this->admin();
        $demoUser = $this->demoUser();

        $this->assertTrue(Gate::forUser($admin)->denies('update', $demoUser));
        $this->assertTrue(Gate::forUser($admin)->denies('delete', $demoUser));
        $this->assertTrue(Gate::forUser($admin)->denies('updatePassword', $demoUser));
        $this->assertTrue(Gate::forUser($admin)->denies('changeRole', $demoUser));
    }

    public function test_policy_allows_update_and_delete_for_non_demo_user(): void
    {
        $admin = $this->admin();
        $regularUser = User::factory()->create(['role' => UserRole::Staff]);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $regularUser));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $regularUser));
    }

    public function test_editing_demo_user_via_url_is_forbidden(): void
    {
        $admin = $this->admin();
        $demoUser = $this->demoUser();

        $this->actingAs($admin)->get("/admin/users/{$demoUser->id}/edit")->assertForbidden();
    }

    public function test_editing_non_demo_user_via_url_is_allowed(): void
    {
        $admin = $this->admin();
        $regularUser = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($admin)->get("/admin/users/{$regularUser->id}/edit")->assertOk();
    }

    public function test_staff_cannot_access_user_management_at_all(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $regularUser = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        $this->actingAs($staff)->get("/admin/users/{$regularUser->id}/edit")->assertForbidden();
    }
}
