<?php

namespace App\Notifications\Customer;

use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingCancelled extends Notification
{
    private const TITLE = 'Booking dibatalkan';

    public function __construct(private readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'fcm'];
    }

    /**
     * @return array{title: string, body: string, data: array<string, string>}
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => self::TITLE,
            'body' => $this->message(),
            'data' => ['type' => 'booking_cancelled', 'booking_id' => (string) $this->booking->id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'booking_cancelled',
            'title' => self::TITLE,
            'message' => $this->message(),
            'booking_id' => $this->booking->id,
        ];
    }

    private function message(): string
    {
        return sprintf(
            'Booking kamu di %s pada %s telah dibatalkan.',
            $this->booking->venue?->name ?? 'venue',
            $this->booking->start_time->format('d M Y, H:i'),
        );
    }
}
