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

    public function test_sends_reminders_for_bookings_starting_soon(): void
    {
        Notification::fake();

        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['customer_account_id' => $account->id]);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'start_time' => now()->addMinutes(45),
            'end_time' => now()->addMinutes(105),
        ]);

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertSentTo($account, BookingReminder::class);
        $this->assertNotNull($booking->fresh()->reminder_1h_sent_at);
    }

    public function test_reports_zero_when_nothing_is_due(): void
    {
        Notification::fake();

        $this->artisan('app:send-booking-reminders')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
