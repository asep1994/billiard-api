<?php

namespace Tests\Feature\Customer;

use App\Enums\Status;
use App\Models\CustomerAccount;
use App\Models\Favorite;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerFavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_favorite_a_venue(): void
    {
        $venue = Venue::factory()->create();

        $this->postJson("/api/v1/customer/venues/{$venue->id}/favorite")->assertUnauthorized();
    }

    public function test_customer_can_favorite_a_venue(): void
    {
        $customer = CustomerAccount::factory()->create();
        $venue = Venue::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/customer/venues/{$venue->id}/favorite")->assertCreated();

        $this->assertDatabaseHas('favorites', [
            'customer_account_id' => $customer->id,
            'venue_id' => $venue->id,
        ]);
    }

    public function test_favoriting_the_same_venue_twice_is_idempotent(): void
    {
        $customer = CustomerAccount::factory()->create();
        $venue = Venue::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/customer/venues/{$venue->id}/favorite")->assertCreated();
        $this->postJson("/api/v1/customer/venues/{$venue->id}/favorite")->assertCreated();

        $this->assertSame(1, Favorite::where('customer_account_id', $customer->id)->count());
    }

    public function test_customer_can_unfavorite_a_venue(): void
    {
        $customer = CustomerAccount::factory()->create();
        $venue = Venue::factory()->create();
        Favorite::create(['customer_account_id' => $customer->id, 'venue_id' => $venue->id]);
        Sanctum::actingAs($customer);

        $this->deleteJson("/api/v1/customer/venues/{$venue->id}/favorite")->assertNoContent();

        $this->assertDatabaseMissing('favorites', [
            'customer_account_id' => $customer->id,
            'venue_id' => $venue->id,
        ]);
    }

    public function test_customer_can_list_their_favorited_venues(): void
    {
        $customer = CustomerAccount::factory()->create();
        $favorited = Venue::factory()->create(['status' => Status::Active]);
        Venue::factory()->create(['status' => Status::Active]);
        Favorite::create(['customer_account_id' => $customer->id, 'venue_id' => $favorited->id]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/customer/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $favorited->id)
            ->assertJsonPath('data.0.is_favorited', true);
    }

    public function test_favorites_list_is_scoped_to_the_authenticated_customer(): void
    {
        $venue = Venue::factory()->create(['status' => Status::Active]);
        $otherCustomer = CustomerAccount::factory()->create();
        Favorite::create(['customer_account_id' => $otherCustomer->id, 'venue_id' => $venue->id]);

        Sanctum::actingAs(CustomerAccount::factory()->create());

        $this->getJson('/api/v1/customer/favorites')->assertOk()->assertJsonCount(0, 'data');
    }
}
