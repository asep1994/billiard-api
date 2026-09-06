<?php

namespace Tests\Feature;

use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BilliardTableTest extends TestCase
{
    use RefreshDatabase;

    private function availableTablesUrl(Venue $venue, string $start, string $end): string
    {
        return '/api/v1/venues/'.$venue->id.'/available-tables?'.http_build_query([
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function test_show_includes_the_related_venue(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson("/api/v1/tables/{$table->id}")
            ->assertOk()
            ->assertJsonPath('data.venue.id', $venue->id);
    }

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

    public function test_available_tables_excludes_a_table_with_an_overlapping_booking(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $bookedTable = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $freeTable = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $bookedTable->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 11:00:00', '2027-01-01 13:00:00'));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $freeTable->id);
    }

    public function test_available_tables_includes_a_table_whose_existing_booking_does_not_overlap(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 12:00:00', '2027-01-01 13:00:00'));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_available_tables_ignores_cancelled_bookings(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        Booking::factory()->cancelled()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 10:00:00', '2027-01-01 12:00:00'));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_available_tables_excludes_tables_under_maintenance(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        BilliardTable::factory()->maintenance()->create(['venue_id' => $venue->id]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 10:00:00', '2027-01-01 12:00:00'));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_available_tables_requires_a_valid_time_range(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 12:00:00', '2027-01-01 10:00:00'));

        $response->assertUnprocessable()->assertJsonValidationErrors('end_time');
    }

    public function test_a_user_cannot_check_availability_for_a_venue_from_another_vendor(): void
    {
        $venue = Venue::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $response = $this->getJson($this->availableTablesUrl($venue, '2027-01-01 10:00:00', '2027-01-01 12:00:00'));

        $response->assertForbidden();
    }
}
