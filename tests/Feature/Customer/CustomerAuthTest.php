<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/v1/customer/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('customer.phone', '081234567890')
            ->assertJsonStructure(['customer' => ['id', 'name', 'phone'], 'token']);

        $this->assertDatabaseHas('customer_accounts', ['phone' => '081234567890']);
    }

    public function test_customer_cannot_register_with_a_duplicate_phone(): void
    {
        CustomerAccount::factory()->create(['phone' => '081234567890']);

        $this->postJson('/api/v1/customer/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_customer_can_login_with_correct_credentials(): void
    {
        CustomerAccount::factory()->create(['phone' => '081234567890']);

        $response = $this->postJson('/api/v1/customer/login', [
            'phone' => '081234567890',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['customer', 'token']);
    }

    public function test_customer_login_fails_with_wrong_password(): void
    {
        CustomerAccount::factory()->create(['phone' => '081234567890']);

        $this->postJson('/api/v1/customer/login', [
            'phone' => '081234567890',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_authenticated_customer_can_view_their_own_profile(): void
    {
        $account = CustomerAccount::factory()->create(['name' => 'Budi Santoso']);
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.name', 'Budi Santoso');
    }

    public function test_authenticated_customer_can_logout(): void
    {
        $account = CustomerAccount::factory()->create();
        Sanctum::actingAs($account);

        $this->postJson('/api/v1/customer/logout')->assertNoContent();
    }

    public function test_a_customer_token_cannot_access_admin_routes(): void
    {
        $account = CustomerAccount::factory()->create();
        Sanctum::actingAs($account);

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_an_admin_token_cannot_access_customer_only_routes(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/customer/me')->assertForbidden();
    }
}
