<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Review;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{vendor: Vendor, venue: Venue, table: BilliardTable, customer: Customer}
     */
    private function makeVendorContext(): array
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $table = BilliardTable::factory()->create(['venue_id' => $venue->id]);
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);

        return compact('vendor', 'venue', 'table', 'customer');
    }

    public function test_staff_can_review_a_completed_booking(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Completed,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/reviews', [
            'booking_id' => $booking->id,
            'rating' => 5,
            'comment' => 'Mejanya rata, AC dingin.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.venue.id', $venue->id)
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->assertDatabaseHas('reviews', ['booking_id' => $booking->id, 'vendor_id' => $vendor->id, 'rating' => 5]);
    }

    public function test_cannot_review_a_booking_that_is_not_completed(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/reviews', [
            'booking_id' => $booking->id,
            'rating' => 4,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('reviews', ['booking_id' => $booking->id]);
    }

    public function test_a_booking_cannot_be_reviewed_twice(): void
    {
        ['vendor' => $vendor, 'venue' => $venue, 'table' => $table, 'customer' => $customer] = $this->makeVendorContext();
        $booking = Booking::factory()->create([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Completed,
        ]);
        Review::factory()->create(['booking_id' => $booking->id, 'vendor_id' => $vendor->id, 'venue_id' => $venue->id, 'customer_id' => $customer->id]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/reviews', [
            'booking_id' => $booking->id,
            'rating' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('booking_id');
    }

    public function test_staff_cannot_review_a_booking_belonging_to_another_vendor(): void
    {
        ['vendor' => $vendor] = $this->makeVendorContext();
        ['venue' => $otherVenue, 'table' => $otherTable, 'customer' => $otherCustomer] = $this->makeVendorContext();
        $otherBooking = Booking::factory()->create([
            'vendor_id' => $otherVenue->vendor_id,
            'venue_id' => $otherVenue->id,
            'billiard_table_id' => $otherTable->id,
            'customer_id' => $otherCustomer->id,
            'status' => BookingStatus::Completed,
        ]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/reviews', [
            'booking_id' => $otherBooking->id,
            'rating' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('booking_id');
    }

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Review::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        Review::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_can_update_a_review(): void
    {
        $vendor = Vendor::factory()->create();
        $review = Review::factory()->create(['vendor_id' => $vendor->id, 'rating' => 2]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->putJson("/api/v1/reviews/{$review->id}", ['rating' => 4])
            ->assertOk()
            ->assertJsonPath('data.rating', 4);
    }

    public function test_staff_cannot_update_a_review(): void
    {
        $vendor = Vendor::factory()->create();
        $review = Review::factory()->create(['vendor_id' => $vendor->id, 'rating' => 2]);

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->putJson("/api/v1/reviews/{$review->id}", ['rating' => 4])->assertForbidden();
    }

    public function test_vendor_admin_can_delete_a_review(): void
    {
        $vendor = Vendor::factory()->create();
        $review = Review::factory()->create(['vendor_id' => $vendor->id]);

        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/reviews/{$review->id}")->assertNoContent();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }
}
