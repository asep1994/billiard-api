<?php

namespace Tests\Feature;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Promotion::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        Promotion::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_vendor_admin_can_create_a_promotion(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $response = $this->postJson('/api/v1/promotions', [
            'code' => 'DISKON20',
            'type' => 'percentage',
            'value' => 20,
            'max_discount' => 30000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'DISKON20')
            ->assertJsonPath('data.is_valid_now', true);
    }

    public function test_staff_cannot_create_a_promotion(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/promotions', [
            'code' => 'DISKON20',
            'type' => 'percentage',
            'value' => 20,
        ])->assertForbidden();
    }

    public function test_staff_can_view_promotions(): void
    {
        $vendor = Vendor::factory()->create();
        Promotion::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/promotions')->assertOk();
    }

    public function test_code_must_be_unique_within_the_same_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Promotion::factory()->create(['vendor_id' => $vendor->id, 'code' => 'DISKON20']);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/promotions', [
            'code' => 'DISKON20',
            'type' => 'percentage',
            'value' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_same_code_is_allowed_for_different_vendors(): void
    {
        $otherVendor = Vendor::factory()->create();
        Promotion::factory()->create(['vendor_id' => $otherVendor->id, 'code' => 'DISKON20']);

        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->postJson('/api/v1/promotions', [
            'code' => 'DISKON20',
            'type' => 'percentage',
            'value' => 10,
        ])->assertCreated();
    }

    public function test_vendor_admin_can_delete_a_promotion(): void
    {
        $vendor = Vendor::factory()->create();
        $promotion = Promotion::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/promotions/{$promotion->id}")->assertNoContent();
    }

    public function test_expired_promotion_is_not_valid_now(): void
    {
        $promotion = Promotion::factory()->expired()->create();

        $this->assertFalse($promotion->isValidNow());
    }

    public function test_fixed_discount_is_capped_at_the_booking_amount(): void
    {
        $promotion = Promotion::factory()->fixed(500000)->make();

        $this->assertSame(100000.0, $promotion->calculateDiscount(100000));
    }

    public function test_percentage_discount_respects_the_max_discount_cap(): void
    {
        $promotion = Promotion::factory()->make([
            'type' => PromotionType::Percentage,
            'value' => 50,
            'max_discount' => 20000,
        ]);

        $this->assertSame(20000.0, $promotion->calculateDiscount(100000));
    }
}
