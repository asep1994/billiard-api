<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Customer\PaymentReceived as CustomerPaymentReceived;
use App\Notifications\PaymentReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Creates a Duitku transaction for a booking. Shared by the admin "Bayar"
 * flow (PaymentController) and the customer app's own checkout, so both
 * channels stay in lockstep with however Duitku integration evolves.
 */
class BookingPaymentService
{
    public function __construct(private readonly DuitkuService $duitku) {}

    /**
     * @return array{status: 'already_paid'|'duitku_failed'|'created', payment: ?Payment, payment_url: ?string, duitku_response: ?array<string, mixed>}
     */
    public function initiate(Booking $booking, string $paymentMethod): array
    {
        if ($booking->payments()->where('status', PaymentGatewayStatus::Paid)->exists()) {
            return ['status' => 'already_paid', 'payment' => null, 'payment_url' => null, 'duitku_response' => null];
        }

        $customer = $booking->customer;
        $payableAmount = $booking->payableAmount();
        $paymentAmount = (int) round($payableAmount);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-'.$booking->id.'-'.Str::upper(Str::random(8)),
            'payment_method' => $paymentMethod,
            'amount' => $payableAmount,
            'status' => PaymentGatewayStatus::Pending,
        ]);

        $duitkuResponse = $this->duitku->createTransaction([
            'paymentAmount' => $paymentAmount,
            'paymentMethod' => $payment->payment_method,
            'merchantOrderId' => $payment->merchant_order_id,
            'productDetails' => "Booking #{$booking->id}",
            'email' => $customer->email ?? 'guest@billiard.test',
            'customerVaName' => $customer->name,
            'phoneNumber' => $customer->phone,
            'callbackUrl' => route('payments.callback'),
            'returnUrl' => config('app.url'),
            'expiryPeriod' => 60,
        ]);

        if (($duitkuResponse['statusCode'] ?? null) !== '00') {
            $payment->update(['status' => PaymentGatewayStatus::Failed]);

            return ['status' => 'duitku_failed', 'payment' => $payment, 'payment_url' => null, 'duitku_response' => $duitkuResponse];
        }

        $payment->update(['duitku_reference' => $duitkuResponse['reference'] ?? null]);

        return [
            'status' => 'created',
            'payment' => $payment->refresh()->load(['booking.customer', 'booking.billiardTable']),
            'payment_url' => $duitkuResponse['paymentUrl'] ?? null,
            'duitku_response' => $duitkuResponse,
        ];
    }

    /**
     * Actively asks Duitku for a transaction's current status and applies it
     * locally if paid. A fallback to the webhook in [markAsPaid]'s only other
     * caller (PaymentController::callback) - Duitku's servers can't reach a
     * callback URL on localhost, so this is what actually completes a
     * payment in local development, and it doubles as a safety net in
     * production if the webhook is ever delayed or missed.
     */
    public function refreshStatus(Payment $payment): Payment
    {
        if ($payment->status !== PaymentGatewayStatus::Paid) {
            $result = $this->duitku->checkTransactionStatus($payment->merchant_order_id);

            if (($result['statusCode'] ?? null) === '00') {
                $this->markAsPaid($payment, $result['reference'] ?? null, $payment->payment_method);
            }
        }

        return $payment->fresh();
    }

    /**
     * Marks a payment (and its booking) as paid, computes the vendor payout
     * split, logs it, and notifies the vendor admins and the customer. Kept
     * idempotent so the webhook and the active status-check fallback can
     * both call it without double-applying the payout or double-notifying.
     */
    public function markAsPaid(Payment $payment, ?string $reference, ?string $paymentMethod): void
    {
        if ($payment->status === PaymentGatewayStatus::Paid) {
            return;
        }

        $commissionRate = (float) $payment->booking->vendor->commission_rate;
        $commissionAmount = round((float) $payment->amount * $commissionRate / 100, 2);
        $vendorPayoutAmount = round((float) $payment->amount - $commissionAmount, 2);

        $payment->update([
            'status' => PaymentGatewayStatus::Paid,
            'duitku_reference' => $reference ?? $payment->duitku_reference,
            'payment_method' => $paymentMethod ?? $payment->payment_method,
            'paid_at' => now(),
            'commission_amount' => $commissionAmount,
            'vendor_payout_amount' => $vendorPayoutAmount,
        ]);

        $payment->booking->update(['payment_status' => PaymentStatus::Paid]);

        ActivityLog::record(
            ActivityAction::Paid,
            'booking',
            $payment->booking_id,
            'Pembayaran BK-'.str_pad((string) $payment->booking_id, 4, '0', STR_PAD_LEFT)." diterima ({$payment->payment_method})",
            $payment->booking->vendor_id,
        );

        $recipients = User::where('vendor_id', $payment->booking->vendor_id)
            ->where('role', UserRole::VendorAdmin)
            ->get();

        if ($payment->booking->user_id) {
            $creator = User::find($payment->booking->user_id);

            if ($creator && ! $recipients->contains('id', $creator->id)) {
                $recipients->push($creator);
            }
        }

        Notification::send($recipients, new PaymentReceived($payment));

        if ($account = $payment->booking->customer?->customerAccount) {
            Notification::send($account, new CustomerPaymentReceived($payment));
        }
    }
}
