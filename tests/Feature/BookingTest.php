<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function makeVendorContext(): array
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id, 'hourly_rate' => 50000]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        return compact('vendor', 'venue', 'table', 'customer');
    }

    public function test_staff_can_create_a_booking_with_an_automatically_calculated_total_price(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_price', '100000.00')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid');
    }

    public function test_show_includes_the_related_venue_table_and_customer(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson("/api/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.venue.id', $venue->id)
            ->assertJsonPath('data.billiard_table.id', $table->id)
            ->assertJsonPath('data.customer.id', $customer->id);
    }

    public function test_a_naive_start_time_is_interpreted_in_the_application_timezone(): void
    {
        // Guards against a real bug: app.timezone defaulted to UTC while the
        // business operates in Asia/Jakarta (UTC+7), so a staff member
        // entering "14:00" got a booking stored as 14:00 UTC and displayed
        // back as 21:00 local. Pinning the exact UTC instant here fails loudly
        // if config('app.timezone') ever regresses to UTC.
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 14:00:00',
            'end_time' => '2027-01-01 16:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.start_time', '2027-01-01T07:00:00.000000Z')
            ->assertJsonPath('data.end_time', '2027-01-01T09:00:00.000000Z');
    }

    public function test_overlapping_booking_on_the_same_table_is_rejected(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 11:00:00',
            'end_time' => '2027-01-01 13:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('billiard_table_id');
    }

    public function test_non_overlapping_booking_on_the_same_table_is_accepted(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 12:00:00',
            'end_time' => '2027-01-01 13:00:00',
        ])->assertCreated();
    }

    public function test_a_cancelled_booking_does_not_block_the_same_time_slot(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Booking::factory()->cancelled()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertCreated();
    }

    public function test_customer_from_a_different_vendor_is_rejected(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $foreignCustomer = Customer::factory()->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $foreignCustomer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_updating_the_time_range_recalculates_the_total_price(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
            'total_price' => 100000,
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", [
            'end_time' => '2027-01-01 13:00:00',
        ])->assertOk()->assertJsonPath('data.total_price', '150000.00');
    }

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Booking::factory()->count(2)->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);
        Booking::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_cannot_delete_a_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->deleteJson("/api/v1/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_vendor_admin_can_cancel_a_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Cancelled->value);
    }
}
