<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerDeviceToken;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SendBookingRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminds_customers_whose_confirmed_booking_starts_within_the_next_hour(): void
    {
        $account = CustomerAccount::factory()->create();
        CustomerDeviceToken::create(['customer_account_id' => $account->id, 'token' => 'device-token']);
        $customer = Customer::factory()->create(['customer_account_id' => $account->id]);
        $booking = Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
        ]);

        $this->mock(FcmService::class, function (MockInterface $mock) use ($account) {
            $mock->shouldReceive('sendToCustomer')
                ->once()
                ->withArgs(fn (CustomerAccount $sentTo) => $sentTo->is($account));
        });

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    public function test_does_not_remind_bookings_outside_the_reminder_window(): void
    {
        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        $farBooking = Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addHours(5),
            'end_time' => now()->addHours(6),
        ]);

        $this->mock(FcmService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendToCustomer');
        });

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        $this->assertNull($farBooking->fresh()->reminder_sent_at);
    }

    public function test_does_not_remind_a_booking_twice(): void
    {
        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        $booking = Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
            'reminder_sent_at' => now()->subMinutes(5),
        ]);

        $this->mock(FcmService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendToCustomer');
        });

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);
    }

    public function test_ignores_bookings_that_are_not_confirmed(): void
    {
        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        $pendingBooking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
        ]);

        $this->mock(FcmService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendToCustomer');
        });

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        $this->assertNull($pendingBooking->fresh()->reminder_sent_at);
    }
}
