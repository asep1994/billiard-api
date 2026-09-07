<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerDeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class FcmService
{
    public function __construct(private readonly Messaging $messaging) {}

    /**
     * Push a notification to every device registered for the given customer.
     * Tokens Firebase reports as invalid or unregistered are pruned so they
     * are not retried on the next send.
     *
     * @param  array<string, string>  $data
     */
    public function sendToCustomer(CustomerAccount $customer, string $title, string $body, array $data = []): void
    {
        $tokens = $customer->deviceTokens()->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FirebaseNotification::create($title, $body))
            ->withData($data);

        $report = $this->messaging->sendMulticast($message, $tokens);

        $staleTokens = [...$report->invalidTokens(), ...$report->unknownTokens()];

        if ($staleTokens !== []) {
            CustomerDeviceToken::whereIn('token', $staleTokens)->delete();
        }
    }
}
