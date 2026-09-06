<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_scoped_to_the_authenticated_users_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Customer::factory()->count(2)->create(['vendor_id' => $vendor->id]);
        Customer::factory()->count(3)->create();

        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_can_create_a_customer_for_their_own_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Walk-in Customer',
            'phone' => '081234567890',
        ]);

        $response->assertCreated()->assertJsonPath('data.vendor_id', $vendor->id);
    }

    public function test_phone_must_be_unique_within_the_same_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        Customer::factory()->create(['vendor_id' => $vendor->id, 'phone' => '081234567890']);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/customers', [
            'name' => 'Duplicate Phone',
            'phone' => '081234567890',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_same_phone_is_allowed_for_different_vendors(): void
    {
        $otherVendor = Vendor::factory()->create();
        Customer::factory()->create(['vendor_id' => $otherVendor->id, 'phone' => '081234567890']);

        $vendor = Vendor::factory()->create();
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->postJson('/api/v1/customers', [
            'name' => 'New Customer',
            'phone' => '081234567890',
        ])->assertCreated();
    }

    public function test_staff_cannot_delete_a_customer(): void
    {
        $vendor = Vendor::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->staff($vendor)->create());

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();
    }

    public function test_vendor_admin_can_delete_a_customer(): void
    {
        $vendor = Vendor::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id]);
        Sanctum::actingAs(User::factory()->vendorAdmin($vendor)->create());

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();
    }

    public function test_vendor_admin_cannot_view_a_customer_from_another_vendor(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs(User::factory()->vendorAdmin()->create());

        $this->getJson("/api/v1/customers/{$customer->id}")->assertForbidden();
    }
}
