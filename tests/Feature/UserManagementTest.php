<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_list_users(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_show_includes_the_related_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/users/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $vendor->id);
    }

    public function test_vendor_admin_only_sees_users_from_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        User::factory()->staff($vendor)->count(2)->create();
        User::factory()->staff()->count(3)->create();

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_vendor_admin_can_create_staff_without_specifying_a_vendor_id(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Staff',
            'email' => 'new-staff@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
    }

    public function test_vendor_admin_cannot_assign_a_user_to_another_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/users', [
            'vendor_id' => $otherVendor->id,
            'name' => 'New Staff',
            'email' => 'new-staff@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
    }

    public function test_vendor_admin_cannot_create_a_super_admin(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_super_admin_must_provide_a_vendor_id_for_non_super_admin_roles(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/users', [
            'name' => 'Orphan Admin',
            'email' => 'orphan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'vendor_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
    }

    public function test_vendor_admin_cannot_delete_a_super_admin(): void
    {
        $vendor = Vendor::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/users/{$superAdmin->id}")->assertForbidden();
    }

    public function test_vendor_admin_cannot_delete_a_user_from_another_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $foreignStaff = User::factory()->staff()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/users/{$foreignStaff->id}")->assertForbidden();
    }

    public function test_a_user_cannot_delete_themselves(): void
    {
        $vendor = Vendor::factory()->create();
        $admin = User::factory()->vendorAdmin($vendor)->create();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")->assertForbidden();
    }

    public function test_vendor_admin_can_delete_their_own_staff(): void
    {
        $vendor = Vendor::factory()->create();
        $staff = User::factory()->staff($vendor)->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/users/{$staff->id}")->assertNoContent();
    }
}
