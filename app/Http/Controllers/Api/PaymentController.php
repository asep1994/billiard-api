<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Enums\PaymentGatewayStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuitkuCallbackRequest;
use App\Http\Requests\InitiatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentReceived;
use App\Services\DuitkuService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $payments = $request->user()->isSuperAdmin()
            ? Payment::query()
            : Payment::whereHas('booking', fn ($query) => $query->where('vendor_id', $request->user()->vendor_id));

        $perPage = min($request->integer('per_page', 15), 100);

        return PaymentResource::collection(
            $payments->with(['booking.customer', 'booking.billiardTable'])->latest()->paginate($perPage)
        );
    }

    /**
     * Create a Duitku transaction for a booking and return the payment URL.
     */
    public function initiate(InitiatePaymentRequest $request, Booking $booking, DuitkuService $duitku)
    {
        $this->authorize('update', $booking);

        if ($booking->payments()->where('status', PaymentGatewayStatus::Paid)->exists()) {
            return response()->json(['message' => 'This booking has already been paid.'], Response::HTTP_CONFLICT);
        }

        $customer = $booking->customer;
        $payableAmount = round((float) $booking->total_price - (float) $booking->discount_amount, 2);
        $paymentAmount = (int) round($payableAmount);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'merchant_order_id' => 'BOOK-'.$booking->id.'-'.Str::upper(Str::random(8)),
            'payment_method' => $request->validated('payment_method'),
            'amount' => $payableAmount,
            'status' => PaymentGatewayStatus::Pending,
        ]);

        $duitkuResponse = $duitku->createTransaction([
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

            return response()->json([
                'message' => 'Failed to create Duitku transaction.',
                'duitku_response' => $duitkuResponse,
            ], Response::HTTP_BAD_GATEWAY);
        }

        $payment->update(['duitku_reference' => $duitkuResponse['reference'] ?? null]);

        return (new PaymentResource($payment->refresh()->load(['booking.customer', 'booking.billiardTable'])))
            ->additional(['payment_url' => $duitkuResponse['paymentUrl'] ?? null])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Handle Duitku's payment notification callback.
     */
    public function callback(DuitkuCallbackRequest $request, DuitkuService $duitku)
    {
        $data = $request->validated();

        $signatureValid = $duitku->verifyCallbackSignature(
            $data['merchantOrderId'],
            (int) $data['amount'],
            $data['signature'],
        );

        if (! $signatureValid) {
            return response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        $payment = Payment::where('merchant_order_id', $data['merchantOrderId'])->first();

        if (! $payment) {
            return response('Order not found', Response::HTTP_NOT_FOUND);
        }

        if ($payment->status === PaymentGatewayStatus::Paid) {
            return response('SUCCESS');
        }

        if ($data['resultCode'] === '00') {
            $commissionRate = (float) $payment->booking->vendor->commission_rate;
            $commissionAmount = round((float) $payment->amount * $commissionRate / 100, 2);
            $vendorPayoutAmount = round((float) $payment->amount - $commissionAmount, 2);

            $payment->update([
                'status' => PaymentGatewayStatus::Paid,
                'duitku_reference' => $data['reference'] ?? $payment->duitku_reference,
                'payment_method' => $data['paymentCode'] ?? $payment->payment_method,
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
        } else {
            $payment->update(['status' => PaymentGatewayStatus::Failed]);
        }

        return response('SUCCESS');
    }
}
