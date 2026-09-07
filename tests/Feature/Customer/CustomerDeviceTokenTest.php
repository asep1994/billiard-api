<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAccount;
use App\Models\CustomerDeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_register_a_device_token(): void
    {
        $this->postJson('/api/v1/customer/device-tokens', ['token' => 'device-token'])->assertUnauthorized();
    }

    public function test_customer_can_register_a_device_token(): void
    {
        $customer = CustomerAccount::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/device-tokens', ['token' => 'device-token'])->assertNoContent();

        $this->assertDatabaseHas('customer_device_tokens', [
            'customer_account_id' => $customer->id,
            'token' => 'device-token',
            'platform' => 'android',
        ]);
    }

    public function test_registering_the_same_token_twice_upserts_instead_of_duplicating(): void
    {
        $customer = CustomerAccount::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/device-tokens', ['token' => 'device-token'])->assertNoContent();
        $this->postJson('/api/v1/customer/device-tokens', ['token' => 'device-token'])->assertNoContent();

        $this->assertSame(1, CustomerDeviceToken::where('token', 'device-token')->count());
    }

    public function test_registering_a_token_already_owned_by_another_customer_reassigns_it(): void
    {
        $previousOwner = CustomerAccount::factory()->create();
        CustomerDeviceToken::create([
            'customer_account_id' => $previousOwner->id,
            'token' => 'device-token',
            'platform' => 'android',
        ]);

        $newOwner = CustomerAccount::factory()->create();
        Sanctum::actingAs($newOwner);

        $this->postJson('/api/v1/customer/device-tokens', ['token' => 'device-token'])->assertNoContent();

        $this->assertDatabaseHas('customer_device_tokens', [
            'token' => 'device-token',
            'customer_account_id' => $newOwner->id,
        ]);
    }

    public function test_customer_can_unregister_a_device_token(): void
    {
        $customer = CustomerAccount::factory()->create();
        CustomerDeviceToken::create([
            'customer_account_id' => $customer->id,
            'token' => 'device-token',
            'platform' => 'android',
        ]);
        Sanctum::actingAs($customer);

        $this->deleteJson('/api/v1/customer/device-tokens?token=device-token')->assertNoContent();

        $this->assertDatabaseMissing('customer_device_tokens', ['token' => 'device-token']);
    }

    public function test_registering_a_device_token_requires_a_token(): void
    {
        $customer = CustomerAccount::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/customer/device-tokens', [])->assertUnprocessable();
    }
}
