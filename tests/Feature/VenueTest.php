<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Venue::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        Venue::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_super_admin_sees_venues_across_all_vendors(): void
    {
        Venue::factory()->count(2)->create();
        Venue::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/venues')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_vendor_admin_can_create_a_venue_scoped_to_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
            'opening_time' => '09:00',
            'closing_time' => '22:00',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
    }

    public function test_vendor_admin_cannot_assign_a_venue_to_another_vendor(): void
    {
        $ownVendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($ownVendor)->create());

        $response = $this->postJson('/api/v1/venues', [
            'vendor_id' => $otherVendor->id,
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $ownVendor->id);
    }

    public function test_staff_cannot_create_a_venue(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ])->assertForbidden();
    }

    public function test_super_admin_must_specify_a_vendor_id_when_creating_a_venue(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Downtown Hall',
            'slug' => 'downtown-hall',
        ])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
    }

    public function test_slug_must_be_unique_within_the_same_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Venue::factory()->create(['vendor_id' => $vendor->id, 'slug' => 'main-hall']);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Main Hall Again',
            'slug' => 'main-hall',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_same_slug_is_allowed_for_different_vendors(): void
    {
        $otherVendor = Vendor::factory()->create();
        Venue::factory()->create(['vendor_id' => $otherVendor->id, 'slug' => 'main-hall']);

        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/venues', [
            'name' => 'Main Hall',
            'slug' => 'main-hall',
        ])->assertCreated();
    }

    public function test_vendor_admin_cannot_view_a_venue_from_another_vendor(): void
    {
        $venue = Venue::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson("/api/v1/venues/{$venue->id}")->assertForbidden();
    }
}
