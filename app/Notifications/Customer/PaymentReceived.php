<?php

namespace App\Notifications\Customer;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification
{
    private const TITLE = 'Pembayaran diterima';

    public function __construct(private readonly Payment $payment) {}

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
            'data' => ['type' => 'payment_received', 'booking_id' => (string) $this->payment->booking_id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_received',
            'title' => self::TITLE,
            'message' => $this->message(),
            'booking_id' => $this->payment->booking_id,
        ];
    }

    private function message(): string
    {
        return sprintf(
            'Pembayaran booking kamu di %s sebesar Rp%s telah kami terima.',
            $this->payment->booking?->venue?->name ?? 'venue',
            number_format((float) $this->payment->amount, 0, ',', '.'),
        );
    }
}
