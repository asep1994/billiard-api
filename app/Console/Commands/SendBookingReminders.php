<?php

namespace App\Console\Commands;

use App\Services\BookingReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-booking-reminders')]
#[Description('Push a reminder (pay up if unpaid, come play if paid) to customers whose booking starts within the next hour')]
class SendBookingReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BookingReminderService $reminders): int
    {
        $sent = $reminders->sendDueReminders();

        $this->info(sprintf('Sent %d booking reminder(s).', $sent));

        return self::SUCCESS;
    }
}
