<?php

namespace App\Notifications\Customer;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingConfirmed extends Notification
{
    public function __construct(private readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['fcm'];
    }

    /**
     * @return array{title: string, body: string, data: array<string, string>}
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Booking dikonfirmasi',
            'body' => sprintf(
                'Booking kamu di %s pada %s sudah dikonfirmasi vendor.',
                $this->booking->venue?->name ?? 'venue',
                $this->booking->start_time->format('d M Y, H:i'),
            ),
            'data' => ['type' => 'booking_confirmed', 'booking_id' => (string) $this->booking->id],
        ];
    }
}
