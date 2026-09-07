<?php

namespace App\Notifications\Channels;

use App\Models\CustomerAccount;
use App\Services\FcmService;
use Illuminate\Notifications\Notification;

class FcmChannel
{
    public function __construct(private readonly FcmService $fcm) {}

    public function send(CustomerAccount $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        $this->fcm->sendToCustomer(
            $notifiable,
            $payload['title'],
            $payload['body'],
            $payload['data'] ?? [],
        );
    }
}
