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
use App\Notifications\Customer\PaymentReceived as CustomerPaymentReceived;
use App\Notifications\PaymentReceived;
use App\Services\BookingPaymentService;
use App\Services\DuitkuService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

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
    public function initiate(InitiatePaymentRequest $request, Booking $booking, BookingPaymentService $paymentService)
    {
        $this->authorize('update', $booking);

        $result = $paymentService->initiate($booking, $request->validated('payment_method'));

        return match ($result['status']) {
            'already_paid' => response()->json(['message' => 'This booking has already been paid.'], Response::HTTP_CONFLICT),
            'duitku_failed' => response()->json([
                'message' => 'Failed to create Duitku transaction.',
                'duitku_response' => $result['duitku_response'],
            ], Response::HTTP_BAD_GATEWAY),
            'created' => (new PaymentResource($result['payment']))
                ->additional(['payment_url' => $result['payment_url']])
                ->response()
                ->setStatusCode(Response::HTTP_CREATED),
        };
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

            if ($account = $payment->booking->customer?->customerAccount) {
                Notification::send($account, new CustomerPaymentReceived($payment));
            }
        } else {
            $payment->update(['status' => PaymentGatewayStatus::Failed]);
        }

        return response('SUCCESS');
    }
}
