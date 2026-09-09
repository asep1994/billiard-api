<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Vendor;
use App\Models\Venue;
use App\Notifications\Customer\BookingReminder;
use App\Services\BookingReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $attributes): Booking
    {
        $vendor = Vendor::factory()->create();
        $venue = Venue::factory()->create(['vendor_id' => $vendor->id]);
        $account = CustomerAccount::factory()->create();
        $customer = Customer::factory()->create(['vendor_id' => $vendor->id, 'customer_account_id' => $account->id]);

        return Booking::factory()->create(array_merge([
            'vendor_id' => $vendor->id,
            'venue_id' => $venue->id,
            'customer_id' => $customer->id,
        ], $attributes));
    }

    public function test_sends_a_payment_reminder_for_an_unpaid_booking_within_the_hour(): void
    {
        Notification::fake();

        $booking = $this->makeBooking([
            'payment_status' => 'unpaid',
            'start_time' => now()->addMinutes(45),
            'end_time' => now()->addMinutes(105),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(1, $sent);
        Notification::assertSentTo(
            $booking->customer->customerAccount,
            BookingReminder::class,
            fn ($notification, $channels, $notifiable) => $notification->toFcm($notifiable)['data']['type'] === 'payment_reminder',
        );
        $this->assertNotNull($booking->fresh()->reminder_1h_sent_at);
    }

    public function test_sends_a_play_reminder_for_a_paid_booking_within_the_hour(): void
    {
        Notification::fake();

        $booking = $this->makeBooking([
            'payment_status' => 'paid',
            'start_time' => now()->addMinutes(45),
            'end_time' => now()->addMinutes(105),
        ]);

        app(BookingReminderService::class)->sendDueReminders();

        Notification::assertSentTo(
            $booking->customer->customerAccount,
            BookingReminder::class,
            fn ($notification, $channels, $notifiable) => $notification->toFcm($notifiable)['data']['type'] === 'booking_reminder',
        );
    }

    public function test_sends_both_checkpoints_at_once_when_the_1h_reminder_was_never_sent(): void
    {
        Notification::fake();

        $booking = $this->makeBooking([
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(2, $sent);
        Notification::assertSentTo($booking->customer->customerAccount, BookingReminder::class, 2);
        $this->assertNotNull($booking->fresh()->reminder_1h_sent_at);
        $this->assertNotNull($booking->fresh()->reminder_30m_sent_at);
    }

    public function test_sends_the_30_minute_checkpoint_once_the_1h_one_was_already_sent(): void
    {
        Notification::fake();

        $booking = $this->makeBooking([
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
            'reminder_1h_sent_at' => now()->subMinutes(30),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(1, $sent);
        $this->assertNotNull($booking->fresh()->reminder_30m_sent_at);
    }

    public function test_does_not_remind_a_booking_twice_for_the_same_checkpoint(): void
    {
        Notification::fake();

        $this->makeBooking([
            'start_time' => now()->addMinutes(45),
            'end_time' => now()->addMinutes(105),
            'reminder_1h_sent_at' => now()->subMinutes(5),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
    }

    public function test_ignores_bookings_more_than_an_hour_away(): void
    {
        Notification::fake();

        $this->makeBooking([
            'start_time' => now()->addHours(3),
            'end_time' => now()->addHours(4),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
    }

    public function test_ignores_cancelled_bookings(): void
    {
        Notification::fake();

        $this->makeBooking([
            'status' => 'cancelled',
            'start_time' => now()->addMinutes(20),
            'end_time' => now()->addMinutes(80),
        ]);

        $sent = app(BookingReminderService::class)->sendDueReminders();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
    }

    public function test_can_be_scoped_to_a_single_vendor(): void
    {
        Notification::fake();

        $inScope = $this->makeBooking(['start_time' => now()->addMinutes(20), 'end_time' => now()->addMinutes(80)]);
        $outOfScope = $this->makeBooking(['start_time' => now()->addMinutes(20), 'end_time' => now()->addMinutes(80)]);

        $sent = app(BookingReminderService::class)->sendDueReminders(vendorId: $inScope->vendor_id);

        $this->assertSame(2, $sent);
        $this->assertNotNull($inScope->fresh()->reminder_1h_sent_at);
        $this->assertNull($outOfScope->fresh()->reminder_1h_sent_at);
    }
}
