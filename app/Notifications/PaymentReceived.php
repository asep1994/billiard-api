<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification
{
    public function __construct(private readonly Payment $payment) {}

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
            'type' => 'payment_received',
            'title' => 'Pembayaran diterima',
            'message' => sprintf(
                'Booking BK-%s telah dibayar sebesar Rp%s',
                str_pad((string) $this->payment->booking_id, 4, '0', STR_PAD_LEFT),
                number_format((float) $this->payment->amount, 0, ',', '.'),
            ),
            'booking_id' => $this->payment->booking_id,
            'payment_id' => $this->payment->id,
        ];
    }
}
