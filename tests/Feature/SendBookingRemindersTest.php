<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Notifications\Customer\BookingReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendBookingRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminds_customers_whose_confirmed_booking_starts_within_the_next_hour(): void
    {
        Notification::fake();

        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['customer_account_id' => $account->id]);
        $booking = Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
        ]);

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertSentTo($account, BookingReminder::class);
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    public function test_does_not_remind_bookings_outside_the_reminder_window(): void
    {
        Notification::fake();

        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        $farBooking = Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addHours(5),
            'end_time' => now()->addHours(6),
        ]);

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($farBooking->fresh()->reminder_sent_at);
    }

    public function test_does_not_remind_a_booking_twice(): void
    {
        Notification::fake();

        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        Booking::factory()->confirmed()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
            'reminder_sent_at' => now()->subMinutes(5),
        ]);

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_ignores_bookings_that_are_not_confirmed(): void
    {
        Notification::fake();

        $customer = Customer::factory()->create(['customer_account_id' => CustomerAccount::factory()]);
        $pendingBooking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(30),
            'end_time' => now()->addMinutes(90),
        ]);

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($pendingBooking->fresh()->reminder_sent_at);
    }
}
