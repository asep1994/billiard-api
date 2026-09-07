<?php

namespace App\Notifications\Customer;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification
{
    public function __construct(private readonly Payment $payment) {}

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
            'title' => 'Pembayaran diterima',
            'body' => sprintf(
                'Pembayaran booking kamu di %s sebesar Rp%s telah kami terima.',
                $this->payment->booking?->venue?->name ?? 'venue',
                number_format((float) $this->payment->amount, 0, ',', '.'),
            ),
            'data' => ['type' => 'payment_received', 'booking_id' => (string) $this->payment->booking_id],
        ];
    }
}
