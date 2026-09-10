<?php

namespace Tests\Feature\Customer;

use App\Enums\Status;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NearbyPlaceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleResponse(array $places): void
    {
        Http::fake([
            'places.googleapis.com/*' => Http::response(['places' => $places]),
        ]);
    }

    public function test_guest_can_list_nearby_places(): void
    {
        $this->fakeGoogleResponse([
            [
                'id' => 'place_1',
                'displayName' => ['text' => 'Some Billiard Hall'],
                'formattedAddress' => 'Jl. Contoh No. 1',
                'location' => ['latitude' => -6.9, 'longitude' => 107.6],
                'rating' => 4.5,
                'userRatingCount' => 20,
            ],
        ]);

        $response = $this->getJson('/api/v1/customer/nearby-places?lat=-6.9&lng=107.6')->assertOk();

        $response->assertJsonPath('data.0.id', 'place_1')
            ->assertJsonPath('data.0.name', 'Some Billiard Hall')
            ->assertJsonPath('data.0.rating', 4.5);
    }

    public function test_lat_and_lng_are_required(): void
    {
        $this->getJson('/api/v1/customer/nearby-places')->assertUnprocessable();
    }

    public function test_a_place_matching_a_partnered_venue_by_name_is_excluded(): void
    {
        Venue::factory()->create(['name' => 'Basket Billiard', 'status' => Status::Active]);

        $this->fakeGoogleResponse([
            [
                'id' => 'place_1',
                'displayName' => ['text' => 'Basket Billiard'],
                'formattedAddress' => 'Jl. Basket No. 299',
                'location' => ['latitude' => -6.91, 'longitude' => 107.61],
            ],
            [
                'id' => 'place_2',
                'displayName' => ['text' => 'Totally Different Hall'],
                'formattedAddress' => 'Jl. Lain No. 5',
                'location' => ['latitude' => -6.95, 'longitude' => 107.65],
            ],
        ]);

        $response = $this->getJson('/api/v1/customer/nearby-places?lat=-6.9&lng=107.6')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'place_2');
    }

    public function test_a_place_within_150_meters_of_a_partnered_venue_is_excluded(): void
    {
        Venue::factory()->create([
            'name' => 'Nama Beda Sekali',
            'status' => Status::Active,
            'latitude' => -6.900000,
            'longitude' => 107.600000,
        ]);

        // ~50m away from the venue above.
        $this->fakeGoogleResponse([
            [
                'id' => 'place_1',
                'displayName' => ['text' => 'Some Other Name'],
                'formattedAddress' => 'Jl. Dekat Banget',
                'location' => ['latitude' => -6.90045, 'longitude' => 107.600000],
            ],
        ]);

        $response = $this->getJson('/api/v1/customer/nearby-places?lat=-6.9&lng=107.6')->assertOk();

        $response->assertJsonCount(0, 'data');
    }
}
