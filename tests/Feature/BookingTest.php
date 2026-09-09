<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use App\Notifications\Customer\BookingCancelled as CustomerBookingCancelled;
use App\Notifications\Customer\BookingConfirmed as CustomerBookingConfirmed;
use App\Notifications\Customer\BookingReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_a_whole_hour_booking_uses_the_tables_duration_package_price_when_set(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'customer' => $customer] = $this->makeVendorContext();
        $table = BilliardTable::factory()->create([
            'venue_id' => $venue->id,
            'hourly_rate' => 60000,
            'duration_prices' => [2 => 110000],
        ]);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        // Linear would be 120000 (2 * 60000); the 2-jam package price wins.
        $response->assertCreated()->assertJsonPath('data.total_price', '110000.00');
    }

    public function test_a_duration_without_a_package_price_falls_back_to_the_hourly_rate(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'customer' => $customer] = $this->makeVendorContext();
        $table = BilliardTable::factory()->create([
            'venue_id' => $venue->id,
            'hourly_rate' => 60000,
            'duration_prices' => [2 => 110000],
        ]);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 13:00:00',
        ]);

        // 3 hours has no package price, so it falls back to 3 * 60000.
        $response->assertCreated()->assertJsonPath('data.total_price', '180000.00');
    }

    public function test_a_valid_promo_code_discounts_the_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $promotion = Promotion::factory()->create([
            'vendor_id' => $vendor->id,
            'code' => 'DISKON20',
            'type' => 'percentage',
            'value' => 20,
            'max_discount' => null,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
            'promo_code' => 'DISKON20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_price', '100000.00')
            ->assertJsonPath('data.discount_amount', '20000.00')
            ->assertJsonPath('data.payable_amount', 80000)
            ->assertJsonPath('data.promotion.code', 'DISKON20');

        $this->assertSame(1, $promotion->fresh()->times_used);
    }

    public function test_an_expired_promo_code_is_rejected(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Promotion::factory()->expired()->create(['vendor_id' => $vendor->id, 'code' => 'EXPIRED10']);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
            'promo_code' => 'EXPIRED10',
        ])->assertUnprocessable()->assertJsonValidationErrors('promo_code');
    }

    public function test_a_promo_code_from_another_vendor_is_rejected(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        Promotion::factory()->create(['code' => 'FOREIGN10']);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings', [
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
            'promo_code' => 'FOREIGN10',
        ])->assertUnprocessable()->assertJsonValidationErrors('promo_code');
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

    public function test_index_can_be_filtered_by_venue_id(): void
    {
        ['vendor' => $vendor, 'venue' => $venueA, 'table' => $tableA, 'customer' => $customer] = $this->makeVendorContext();
        $venueB = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $tableB = BilliardTable::factory()->create(['venue_id' => $venueB->id]);

        Booking::factory()->count(2)->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venueA->id,
            'billiard_table_id' => $tableA->id,
            'customer_id' => $customer->id,
        ]);
        Booking::factory()->count(3)->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venueB->id,
            'billiard_table_id' => $tableB->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson("/api/v1/bookings?venue_id={$venueA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
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

    public function test_confirming_a_booking_pushes_a_notification_to_the_customers_linked_account(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Pending,
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'confirmed'])->assertOk();

        Notification::assertSentTo($account, CustomerBookingConfirmed::class);
    }

    public function test_cancelling_a_booking_pushes_a_notification_to_the_customers_linked_account(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])->assertOk();

        Notification::assertSentTo($account, CustomerBookingCancelled::class);
    }

    public function test_updating_a_booking_without_a_status_change_does_not_push_a_notification(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => '2027-01-01 10:00:00',
            'end_time' => '2027-01-01 12:00:00',
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", ['end_time' => '2027-01-01 13:00:00'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_walk_in_customers_without_a_linked_account_do_not_break_the_status_update(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
        ]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Confirmed->value);

        Notification::assertNothingSent();
    }

    public function test_staff_can_trigger_reminders_scoped_to_their_own_vendor(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
        ]);

        ['vendor' => $otherVendor, 'venue' => $otherVenue, 'table' => $otherTable] = $this->makeVendorContext();
        $otherAccount = CustomerAccount::factory()->create();
        $otherCustomer = Customer::factory()->create(['vendor_id' => $otherVendor->id, 'customer_account_id' => $otherAccount->id]);
        Booking::factory()->create([
            'vendor_id' => $otherVendor->id,
            'venue_id' => $otherVenue->id,
            'billiard_table_id' => $otherTable->id,
            'customer_id' => $otherCustomer->id,
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/bookings/send-reminders')
            ->assertOk()
            ->assertJsonPath('sent', 2);

        Notification::assertSentTo($account, BookingReminder::class);
        Notification::assertNotSentTo($otherAccount, BookingReminder::class);
    }

    public function test_super_admin_can_scope_reminders_to_a_specific_vendor(): void
    {
        Notification::fake();

        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table] = $this->makeVendorContext();
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);
        Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
        ]);

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/v1/bookings/send-reminders', ['vendor_id' => $vendor->id])
            ->assertOk()
            ->assertJsonPath('sent', 2);
    }
}
