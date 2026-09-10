<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonPath('customer.email', 'budi@example.test')
            ->assertJsonStructure(['customer' => ['id', 'name', 'phone', 'email'], 'token']);

        $this->assertDatabaseHas('customer_accounts', ['email' => 'budi@example.test']);
    }

    public function test_customer_cannot_register_without_an_email(): void
    {
        $this->postJson('/api/v1/customer/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_customer_cannot_register_with_a_duplicate_email(): void
    {
        CustomerAccount::factory()->create(['email' => 'budi@example.test']);

        $this->postJson('/api/v1/customer/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_customer_can_login_with_correct_credentials(): void
    {
        CustomerAccount::factory()->create(['email' => 'budi@example.test']);

        $response = $this->postJson('/api/v1/customer/login', [
            'email' => 'budi@example.test',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['customer', 'token']);
    }

    public function test_customer_login_fails_with_wrong_password(): void
    {
        CustomerAccount::factory()->create(['email' => 'budi@example.test']);

        $this->postJson('/api/v1/customer/login', [
            'email' => 'budi@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
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

    public function test_customer_can_update_their_name_email_and_phone(): void
    {
        $account = CustomerAccount::factory()->create(['name' => 'Budi Lama', 'email' => 'lama@example.test', 'phone' => '081111111111']);
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/customer/me', [
            'name' => 'Budi Baru',
            'email' => 'baru@example.test',
            'phone' => '082222222222',
        ])->assertOk()->assertJsonPath('data.name', 'Budi Baru');

        $this->assertSame('baru@example.test', $account->fresh()->email);
        $this->assertSame('082222222222', $account->fresh()->phone);
    }

    public function test_customer_cannot_update_email_to_one_already_taken(): void
    {
        CustomerAccount::factory()->create(['email' => 'taken@example.test']);
        $account = CustomerAccount::factory()->create(['email' => 'mine@example.test']);
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/customer/me', [
            'name' => $account->name,
            'email' => 'taken@example.test',
            'phone' => $account->phone,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_customer_can_change_their_password_with_the_correct_current_password(): void
    {
        $account = CustomerAccount::factory()->create();
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/customer/me', [
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
            'current_password' => 'password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $account->fresh()->password));
    }

    public function test_customer_cannot_change_password_with_the_wrong_current_password(): void
    {
        $account = CustomerAccount::factory()->create();
        Sanctum::actingAs($account);

        $this->putJson('/api/v1/customer/me', [
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $account->fresh()->password));
    }

    public function test_guest_cannot_update_a_profile(): void
    {
        $this->putJson('/api/v1/customer/me', ['name' => 'x', 'email' => 'x@example.test', 'phone' => '08123'])
            ->assertUnauthorized();
    }
}
