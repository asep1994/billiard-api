<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\FcmService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-booking-reminders')]
#[Description('Push a reminder to customers whose confirmed booking starts within the next hour')]
class SendBookingReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcm): int
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->whereBetween('start_time', [now(), now()->addHour()])
            ->with(['venue', 'customer.customerAccount'])
            ->get();

        foreach ($bookings as $booking) {
            $account = $booking->customer?->customerAccount;

            if (! $account) {
                continue;
            }

            $fcm->sendToCustomer(
                $account,
                'Booking kamu segera dimulai',
                sprintf(
                    'Booking di %s jam %s. Jangan lupa datang ya!',
                    $booking->venue?->name ?? 'venue',
                    $booking->start_time->format('H:i'),
                ),
                ['type' => 'booking_reminder', 'booking_id' => (string) $booking->id],
            );

            $booking->forceFill(['reminder_sent_at' => now()])->save();
        }

        $this->info(sprintf('Sent %d booking reminder(s).', $bookings->count()));

        return self::SUCCESS;
    }
}
