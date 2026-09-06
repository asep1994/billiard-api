<?php

namespace Tests\Feature\Customer;

use App\Enums\Status;
use App\Enums\TableStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVenueBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_browse_active_venues(): void
    {
        Venue::factory()->count(2)->create(['status' => Status::Active]);
        Venue::factory()->create(['status' => Status::Inactive]);

        $this->getJson('/api/v1/customer/venues')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_can_view_a_single_active_venue_with_its_tables(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Available]);
        BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Maintenance]);

        $response = $this->getJson("/api/v1/customer/venues/{$venue->id}")->assertOk();

        $response->assertJsonCount(1, 'data.tables');
    }

    public function test_guest_cannot_view_an_inactive_venue(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Inactive]);

        $this->getJson("/api/v1/customer/venues/{$venue->id}")->assertNotFound();
    }

    public function test_guest_can_list_available_tables_for_a_time_range(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id, 'status' => Status::Active]);
        $freeTable = BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Available]);
        $bookedTable = BilliardTable::factory()->create(['venue_id' => $venue->id, 'status' => TableStatus::Available]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $bookedTable->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        $response = $this->getJson("/api/v1/customer/venues/{$venue->id}/available-tables?".http_build_query([
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]))->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $freeTable->id);
    }
}
