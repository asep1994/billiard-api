<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAccount;
use App\Models\CustomerPasswordResetCode;
use App\Notifications\Customer\PasswordResetCode as PasswordResetCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_receives_a_reset_code_by_email(): void
    {
        Notification::fake();

        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);

        $this->postJson('/api/v1/customer/forgot-password', ['email' => 'budi@example.test'])
            ->assertOk();

        Notification::assertSentTo($account, PasswordResetCodeNotification::class);
        $this->assertSame(1, $account->passwordResetCodes()->count());
    }

    public function test_an_unregistered_email_is_rejected(): void
    {
        $this->postJson('/api/v1/customer/forgot-password', ['email' => 'nobody@example.test'])
            ->assertNotFound();
    }

    public function test_a_customer_can_reset_their_password_with_a_valid_code(): void
    {
        Notification::fake();

        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);

        $this->postJson('/api/v1/customer/forgot-password', ['email' => 'budi@example.test']);

        $code = null;
        Notification::assertSentTo($account, PasswordResetCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => $code,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $account->fresh()->password));
    }

    public function test_a_used_code_cannot_be_reused(): void
    {
        Notification::fake();

        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);
        $this->postJson('/api/v1/customer/forgot-password', ['email' => 'budi@example.test']);

        $code = null;
        Notification::assertSentTo($account, PasswordResetCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => $code,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => $code,
            'password' => 'another-password123',
            'password_confirmation' => 'another-password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);
        CustomerPasswordResetCode::create([
            'customer_account_id' => $account->id,
            'code' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => '123456',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);
        CustomerPasswordResetCode::create([
            'customer_account_id' => $account->id,
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => '654321',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_resetting_the_password_revokes_existing_tokens(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'budi@example.test']);
        $account->createToken('customer-app');
        CustomerPasswordResetCode::create([
            'customer_account_id' => $account->id,
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/v1/customer/reset-password', [
            'email' => 'budi@example.test',
            'code' => '123456',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertSame(0, $account->fresh()->tokens()->count());
    }
}
