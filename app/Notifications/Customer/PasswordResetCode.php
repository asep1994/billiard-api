<?php

namespace App\Notifications\Customer;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCode extends Notification
{
    public function __construct(public readonly string $code) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kode Reset Password Unity Billiard')
            ->greeting("Halo, {$notifiable->name}!")
            ->line('Kami menerima permintaan untuk mengatur ulang password akun kamu.')
            ->line("Kode reset password kamu: {$this->code}")
            ->line('Kode ini berlaku selama 15 menit.')
            ->line('Kalau kamu tidak meminta ini, abaikan saja email ini.');
    }
}
