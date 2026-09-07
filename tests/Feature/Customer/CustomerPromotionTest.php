<?php

namespace Tests\Feature\Customer;

use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_active_promotions(): void
    {
        Promotion::factory()->create(['is_active' => true, 'starts_at' => null, 'expires_at' => null]);

        $this->getJson('/api/v1/customer/promotions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_inactive_promotions_are_excluded(): void
    {
        Promotion::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/customer/promotions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expired_promotions_are_excluded(): void
    {
        Promotion::factory()->create(['is_active' => true, 'expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/customer/promotions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_promotions_that_have_not_started_yet_are_excluded(): void
    {
        Promotion::factory()->create(['is_active' => true, 'starts_at' => now()->addDay()]);

        $this->getJson('/api/v1/customer/promotions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_currently_running_promotion_is_included(): void
    {
        Promotion::factory()->create([
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/customer/promotions')->assertOk()->assertJsonCount(1, 'data');
    }
}
