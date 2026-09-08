<?php

namespace App\Console\Commands;

use App\Enums\PaymentGatewayStatus;
use App\Models\Payment;
use App\Services\BookingPaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payment:simulate {payment : Payment ID or merchant order ID}')]
#[Description('Mark a pending payment as paid, the same way a real Duitku webhook would. For local testing only - Duitku sandbox cannot reach a callback URL on localhost, so this is the standard way to complete a booking end-to-end in dev.')]
class SimulatePaymentSuccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BookingPaymentService $paymentService): int
    {
        if (! (bool) config('services.duitku.sandbox')) {
            $this->error('Refusing to simulate a payment outside of Duitku sandbox mode.');

            return self::FAILURE;
        }

        $identifier = $this->argument('payment');

        $payment = is_numeric($identifier)
            ? Payment::find($identifier)
            : Payment::where('merchant_order_id', $identifier)->first();

        if (! $payment) {
            $this->error("Payment not found: {$identifier}");

            return self::FAILURE;
        }

        if ($payment->status === PaymentGatewayStatus::Paid) {
            $this->info("Payment #{$payment->id} is already paid.");

            return self::SUCCESS;
        }

        $paymentService->markAsPaid($payment, 'SIMULATED-'.now()->timestamp, $payment->payment_method);

        $this->info("Payment #{$payment->id} (booking #{$payment->booking_id}) marked as paid.");

        return self::SUCCESS;
    }
}
