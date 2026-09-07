<?php

namespace App\Services;

use App\Enums\PaymentGatewayStatus;
use App\Models\Booking;
use App\Models\Payment;
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
}
