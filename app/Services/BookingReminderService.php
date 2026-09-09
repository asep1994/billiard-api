<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Customer\BookingReminder;
use Illuminate\Support\Facades\Notification;

/**
 * Finds bookings starting soon and pushes a reminder - "buruan bayar" for
 * still-unpaid bookings, "jangan lupa datang" for paid ones - at two
 * checkpoints (1 hour and 30 minutes before start). Triggered manually from
 * the admin dashboard's "Kirim Reminder" button rather than a scheduler, so
 * each checkpoint is tracked independently and can be caught on whatever
 * cadence a vendor actually clicks the button.
 */
class BookingReminderService
{
    /**
     * @return int the number of reminders actually sent
     */
    public function sendDueReminders(?int $vendorId = null, ?int $venueId = null): int
    {
        $query = Booking::query()
            ->where('status', '!=', BookingStatus::Cancelled)
            ->where('start_time', '>', now())
            ->where('start_time', '<=', now()->addHour())
            ->where(function ($query) {
                $query->whereNull('reminder_1h_sent_at')->orWhereNull('reminder_30m_sent_at');
            });

        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }

        if ($venueId) {
            $query->where('venue_id', $venueId);
        }

        $bookings = $query->with(['venue', 'customer.customerAccount'])->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            $account = $booking->customer?->customerAccount;

            if (! $account) {
                continue;
            }

            $minutesUntilStart = now()->diffInMinutes($booking->start_time, false);

            if ($booking->reminder_1h_sent_at === null) {
                Notification::send($account, new BookingReminder($booking, 60));
                $booking->forceFill(['reminder_1h_sent_at' => now()])->save();
                $sent++;
            }

            if ($minutesUntilStart <= 30 && $booking->reminder_30m_sent_at === null) {
                Notification::send($account, new BookingReminder($booking, 30));
                $booking->forceFill(['reminder_30m_sent_at' => now()])->save();
                $sent++;
            }
        }

        return $sent;
    }
}
