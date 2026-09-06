<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BilliardTable;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        ActivityLog::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        ActivityLog::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->getJson('/api/v1/activity-logs')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_cannot_view_activity_logs(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/activity-logs')->assertForbidden();
    }

    public function test_creating_a_customer_records_an_activity_log_entry(): void
    {
        $vendor = Vendor::factory()->create();
        $admin = User::factory()->vendorAdmin($vendor)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/customers', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
        ])->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'vendor_id' => $vendor->id,
            'user_id' => $admin->id,
            'action' => 'created',
            'subject_type' => 'customer',
        ]);
    }

    public function test_deleting_a_table_records_an_activity_log_entry(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id, 'name' => 'Table 9']);
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/tables/{$table->id}")->assertNoContent();

        $this->assertDatabaseHas('activity_logs', [
            'vendor_id' => $vendor->id,
            'action' => 'deleted',
            'subject_type' => 'billiard_table',
            'description' => "{$admin->name} menghapus meja Table 9",
        ]);
    }

    public function test_cancelling_a_booking_records_an_activity_log_entry(): void
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        $admin = User::factory()->vendorAdmin($vendor)->create();

        Sanctum::actingAs($admin);

        $booking = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->json('data');

        $this->putJson("/api/v1/bookings/{$booking['id']}", ['status' => 'cancelled'])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'vendor_id' => $vendor->id,
            'action' => 'cancelled',
            'subject_type' => 'booking',
            'subject_id' => $booking['id'],
        ]);
    }
}
