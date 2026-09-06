<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_vendors(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        Vendor::factory()->count(2)->create();

        $this->getJson('/api/v1/vendors')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_cannot_list_vendors(): void
    {
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson('/api/v1/vendors')->assertForbidden();
    }

    public function test_super_admin_can_create_a_vendor(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->postJson('/api/v1/vendors', [
            'name' => 'Champion Billiard',
            'slug' => 'champion-billiard',
            'email' => 'contact@champion.test',
        ]);

        $response->assertCreated()->assertJsonPath('data.slug', 'champion-billiard');
        $this->assertDatabaseHas('vendors', ['slug' => 'champion-billiard', 'status' => 'active']);
    }

    public function test_vendor_admin_cannot_create_a_vendor(): void
    {
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->postJson('/api/v1/vendors', [
            'name' => 'Champion Billiard',
            'slug' => 'champion-billiard',
        ])->assertForbidden();
    }

    public function test_vendor_admin_can_view_and_update_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->getJson("/api/v1/vendors/{$vendor->id}")->assertOk();

        $this->putJson("/api/v1/vendors/{$vendor->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_vendor_admin_cannot_view_another_vendor(): void
    {
        $ownVendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($ownVendor)->create());

        $this->getJson("/api/v1/vendors/{$otherVendor->id}")->assertForbidden();
    }

    public function test_vendor_admin_cannot_delete_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/vendors/{$vendor->id}")->assertForbidden();
    }

    public function test_super_admin_can_delete_a_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->deleteJson("/api/v1/vendors/{$vendor->id}")->assertNoContent();
        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
    }
}
