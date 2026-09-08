<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentGatewayStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DuitkuCallbackRequest;
use App\Http\Requests\InitiatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingPaymentService;
use App\Services\DuitkuService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    public function callback(DuitkuCallbackRequest $request, DuitkuService $duitku, BookingPaymentService $paymentService)
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
            $paymentService->markAsPaid($payment, $data['reference'] ?? null, $data['paymentCode'] ?? null);
        } else {
            $payment->update(['status' => PaymentGatewayStatus::Failed]);
        }

        return response('SUCCESS');
    }
}
