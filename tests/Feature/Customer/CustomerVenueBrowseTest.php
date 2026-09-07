<?php

namespace Tests\Feature\Customer;

use App\Enums\Status;
use App\Enums\TableStatus;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Favorite;
use App\Models\Review;
use App\Models\Vendor;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_venues_are_sorted_nearest_first_when_coordinates_are_given(): void
    {
        // Reference point: central Bandung.
        $near = Venue::factory()->create(['status' => Status::Active, 'latitude' => -6.9175, 'longitude' => 107.6191]);
        $far = Venue::factory()->create(['status' => Status::Active, 'latitude' => -6.8175, 'longitude' => 107.6191]);
        $medium = Venue::factory()->create(['status' => Status::Active, 'latitude' => -6.9675, 'longitude' => 107.6191]);

        $response = $this->getJson('/api/v1/customer/venues?'.http_build_query([
            'lat' => -6.9175,
            'lng' => 107.6191,
        ]))->assertOk();

        $response->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.1.id', $medium->id)
            ->assertJsonPath('data.2.id', $far->id)
            ->assertJsonPath('data.0.distance_km', 0);
    }

    public function test_venues_without_coordinates_are_excluded_from_nearby_search(): void
    {
        Venue::factory()->create(['status' => Status::Active, 'latitude' => null, 'longitude' => null]);
        $located = Venue::factory()->create(['status' => Status::Active, 'latitude' => -6.9175, 'longitude' => 107.6191]);

        $response = $this->getJson('/api/v1/customer/venues?'.http_build_query([
            'lat' => -6.9175,
            'lng' => 107.6191,
        ]))->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $located->id);
    }

    public function test_lng_is_required_when_lat_is_given(): void
    {
        $this->getJson('/api/v1/customer/venues?lat=-6.9175')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lng');
    }

    public function test_distance_km_is_absent_when_not_searching_nearby(): void
    {
        Venue::factory()->create(['status' => Status::Active, 'latitude' => -6.9175, 'longitude' => 107.6191]);

        $this->getJson('/api/v1/customer/venues')
            ->assertOk()
            ->assertJsonPath('data.0.distance_km', null);
    }

    public function test_venue_exposes_average_rating_and_review_count(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        Review::factory()->create(['venue_id' => $venue->id, 'rating' => 4]);
        Review::factory()->create(['venue_id' => $venue->id, 'rating' => 5]);

        $this->getJson('/api/v1/customer/venues')
            ->assertOk()
            ->assertJsonPath('data.0.rating', 4.5)
            ->assertJsonPath('data.0.reviews_count', 2);
    }

    public function test_venue_without_reviews_has_null_rating_and_zero_count(): void
    {
        Venue::factory()->create(['status' => Status::Active]);

        $this->getJson('/api/v1/customer/venues')
            ->assertOk()
            ->assertJsonPath('data.0.rating', null)
            ->assertJsonPath('data.0.reviews_count', 0);
    }

    public function test_venue_exposes_the_cheapest_table_rate_as_price_from(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        BilliardTable::factory()->create(['venue_id' => $venue->id, 'hourly_rate' => 75000]);
        BilliardTable::factory()->create(['venue_id' => $venue->id, 'hourly_rate' => 50000]);

        $this->getJson('/api/v1/customer/venues')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', 50000);
    }

    public function test_venues_can_be_filtered_to_only_those_open_right_now(): void
    {
        $this->travelTo(now()->setTime(14, 0));

        $open = Venue::factory()->create(['status' => Status::Active, 'opening_time' => '10:00', 'closing_time' => '23:00']);
        Venue::factory()->create(['status' => Status::Active, 'opening_time' => '18:00', 'closing_time' => '23:00']);

        $this->getJson('/api/v1/customer/venues?open_now=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    public function test_venues_can_be_sorted_by_rating(): void
    {
        $lowRated = Venue::factory()->create(['status' => Status::Active]);
        Review::factory()->create(['venue_id' => $lowRated->id, 'rating' => 2]);

        $highRated = Venue::factory()->create(['status' => Status::Active]);
        Review::factory()->create(['venue_id' => $highRated->id, 'rating' => 5]);

        $this->getJson('/api/v1/customer/venues?sort=rating')
            ->assertOk()
            ->assertJsonPath('data.0.id', $highRated->id)
            ->assertJsonPath('data.1.id', $lowRated->id);
    }

    public function test_is_favorited_reflects_the_authenticated_customers_favorites(): void
    {
        $customer = CustomerAccount::factory()->create();
        $favorited = Venue::factory()->create(['status' => Status::Active]);
        $notFavorited = Venue::factory()->create(['status' => Status::Active]);
        Favorite::create(['customer_account_id' => $customer->id, 'venue_id' => $favorited->id]);

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/v1/customer/venues')->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$favorited->id]['is_favorited']);
        $this->assertFalse($byId[$notFavorited->id]['is_favorited']);
    }

    public function test_guest_sees_is_favorited_as_false(): void
    {
        Venue::factory()->create(['status' => Status::Active]);

        $this->getJson('/api/v1/customer/venues')
            ->assertOk()
            ->assertJsonPath('data.0.is_favorited', false);
    }
}
