<?php

namespace App\Notifications;

use App\Models\PartnerLead;
use Illuminate\Notifications\Notification;

class NewPartnerLead extends Notification
{
    public function __construct(private readonly PartnerLead $lead) {}

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
            'type' => 'new_partner_lead',
            'title' => 'Calon mitra baru',
            'message' => "Ada yang merekomendasikan \"{$this->lead->name}\" buat gabung jadi mitra Unity Billiard.",
            'partner_lead_id' => $this->lead->id,
        ];
    }
}
