<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingCancelledByCustomer extends Notification
{
    public function __construct(private readonly Booking $booking) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'booking_cancelled_by_customer',
            'title' => 'Booking dibatalkan pelanggan',
            'message' => sprintf(
                '%s membatalkan booking BK-%s (%s, %s)',
                $this->booking->customer?->name ?? 'Pelanggan',
                str_pad((string) $this->booking->id, 4, '0', STR_PAD_LEFT),
                $this->booking->billiardTable?->name ?? 'meja',
                $this->booking->start_time?->format('d M Y, H:i'),
            ),
            'booking_id' => $this->booking->id,
        ];
    }
}
