<?php

namespace Tests\Feature\Customer;

use App\Enums\Status;
use App\Models\Customer;
use App\Models\Review;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_reviews_for_an_active_venue(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        Review::factory()->count(2)->create(['venue_id' => $venue->id]);
        Review::factory()->create();

        $this->getJson("/api/v1/customer/venues/{$venue->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_review_exposes_reviewer_name_but_not_contact_details(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        $customer = Customer::factory()->create(['name' => 'Budi Santoso', 'phone' => '0812345', 'email' => 'budi@test.com']);
        Review::factory()->create(['venue_id' => $venue->id, 'customer_id' => $customer->id, 'rating' => 5, 'comment' => 'Mantap']);

        $response = $this->getJson("/api/v1/customer/venues/{$venue->id}/reviews")->assertOk();

        $response->assertJsonPath('data.0.customer_name', 'Budi Santoso')
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.comment', 'Mantap')
            ->assertJsonMissingPath('data.0.customer.phone')
            ->assertJsonMissingPath('data.0.customer.email')
            ->assertJsonMissingPath('data.0.booking');
    }

    public function test_guest_cannot_list_reviews_for_an_inactive_venue(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Inactive]);

        $this->getJson("/api/v1/customer/venues/{$venue->id}/reviews")->assertNotFound();
    }
}
