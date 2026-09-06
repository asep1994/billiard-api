<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BilliardTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        BilliardTable::factory()->count(2)->create(['venue_id' => $venue->id]);
        BilliardTable::factory()->count(4)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/tables')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_can_create_a_table_for_their_own_venue(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/tables', [
            'venue_id' => $venue->id,
            'name' => 'Table 1',
            'type' => '8_ball',
            'hourly_rate' => 50000,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'available');
    }

    public function test_vendor_admin_cannot_create_a_table_for_another_vendors_venue(): void
    {
        $otherVenue = Venue::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->postJson('/api/v1/tables', [
            'venue_id' => $otherVenue->id,
            'name' => 'Table 1',
            'type' => '8_ball',
            'hourly_rate' => 50000,
        ])->assertUnprocessable()->assertJsonValidationErrors('venue_id');
    }

    public function test_staff_cannot_create_a_table(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/tables', [
            'venue_id' => $venue->id,
            'name' => 'Table 1',
            'type' => '8_ball',
            'hourly_rate' => 50000,
        ])->assertForbidden();
    }

    public function test_table_name_must_be_unique_within_the_same_venue(): void
    {
        $venue = Venue::factory()->create();
        BilliardTable::factory()->create(['venue_id' => $venue->id, 'name' => 'Table 1']);
        Sanctum::actingAs(User::factory()->vendorAdmin(Vendor::find($venue->vendor_id))->create());

        $this->postJson('/api/v1/tables', [
            'venue_id' => $venue->id,
            'name' => 'Table 1',
            'type' => '8_ball',
            'hourly_rate' => 50000,
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_vendor_admin_can_update_table_status(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/tables/{$table->id}", ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance');
    }

    public function test_staff_cannot_update_table_status(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->putJson("/api/v1/tables/{$table->id}", ['status' => 'maintenance'])
            ->assertForbidden();
    }
}
