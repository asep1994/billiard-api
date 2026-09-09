<?php

namespace App\Notifications\Customer;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use Illuminate\Notifications\Notification;

class BookingReminder extends Notification
{
    /**
     * @param  int  $minutesBefore  How far ahead of `start_time` this reminder fired (60 or 30) - purely for wording ("1 jam lagi" vs "30 menit lagi").
     */
    public function __construct(private readonly Booking $booking, private readonly int $minutesBefore) {}

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
            'title' => $this->title(),
            'body' => $this->message(),
            'data' => ['type' => $this->type(), 'booking_id' => (string) $this->booking->id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'message' => $this->message(),
            'booking_id' => $this->booking->id,
        ];
    }

    private function isUnpaid(): bool
    {
        return $this->booking->payment_status === PaymentStatus::Unpaid;
    }

    private function type(): string
    {
        return $this->isUnpaid() ? 'payment_reminder' : 'booking_reminder';
    }

    private function title(): string
    {
        return $this->isUnpaid() ? 'Booking kamu belum dibayar' : 'Booking kamu segera dimulai';
    }

    private function message(): string
    {
        $venue = $this->booking->venue?->name ?? 'venue';
        $time = $this->booking->start_time->format('H:i');
        $when = $this->minutesBefore >= 60 ? '1 jam lagi' : '30 menit lagi';

        if ($this->isUnpaid()) {
            return "Booking kamu di {$venue} jam {$time} dimulai {$when}, tapi belum dibayar. Segera selesaikan pembayaran ya, sebelum slotnya hangus!";
        }

        return "Booking di {$venue} jam {$time} dimulai {$when}. Jangan lupa datang ya!";
    }
}
