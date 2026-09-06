<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\ActivityAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PaymentResource;
use App\Models\ActivityLog;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\NewBookingCreated;
use App\Services\BookingPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

class BookingController extends Controller
{
    /**
     * Display the authenticated customer's own bookings, across every vendor.
     */
    public function index(Request $request)
    {
        $account = $request->user();

        $bookings = Booking::whereHas('customer', fn ($query) => $query->where('customer_account_id', $account->id));

        $perPage = min($request->integer('per_page', 15), 100);

        return BookingResource::collection(
            $bookings->with(['venue', 'billiardTable', 'customer', 'promotion'])->latest('start_time')->paginate($perPage)
        );
    }

    /**
     * Create a self-service booking at any vendor's venue.
     */
    public function store(StoreCustomerBookingRequest $request)
    {
        $account = $request->user();
        $venue = Venue::findOrFail($request->validated('venue_id'));
        $table = BilliardTable::findOrFail($request->validated('billiard_table_id'));

        $customer = Customer::firstOrCreate(
            ['vendor_id' => $venue->vendor_id, 'phone' => $account->phone],
            ['name' => $account->name, 'email' => $account->email, 'customer_account_id' => $account->id],
        );

        if (! $customer->customer_account_id) {
            $customer->update(['customer_account_id' => $account->id]);
        }

        $totalPrice = Booking::calculateTotalPrice($table, $request->validated('start_time'), $request->validated('end_time'));

        $promotion = null;
        $discountAmount = 0;

        if ($request->filled('promo_code')) {
            $promotion = Promotion::where('vendor_id', $venue->vendor_id)
                ->where('code', $request->validated('promo_code'))
                ->first();

            if ($promotion) {
                $discountAmount = $promotion->calculateDiscount($totalPrice);
            }
        }

        $booking = Booking::create([
            'vendor_id' => $venue->vendor_id,
            'venue_id' => $venue->id,
            'billiard_table_id' => $table->id,
            'customer_id' => $customer->id,
            'user_id' => null,
            'promotion_id' => $promotion?->id,
            'start_time' => $request->validated('start_time'),
            'end_time' => $request->validated('end_time'),
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Unpaid,
            'total_price' => $totalPrice,
            'discount_amount' => $discountAmount,
            'notes' => $request->validated('notes'),
        ])->load(['venue', 'billiardTable', 'customer', 'promotion']);

        $promotion?->increment('times_used');

        ActivityLog::record(
            ActivityAction::Created,
            'booking',
            $booking->id,
            "{$account->name} membuat booking BK-".str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT).' lewat aplikasi',
            $booking->vendor_id,
        );

        $vendorAdmins = User::where('vendor_id', $booking->vendor_id)
            ->where('role', UserRole::VendorAdmin)
            ->get();

        Notification::send($vendorAdmins, new NewBookingCreated($booking));

        return (new BookingResource($booking))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display one of the authenticated customer's own bookings.
     */
    public function show(Request $request, Booking $booking)
    {
        $this->authorizeOwnBooking($request, $booking);

        return new BookingResource($booking->load(['venue', 'billiardTable', 'customer', 'promotion']));
    }

    /**
     * Create a Duitku transaction for one of the customer's own bookings.
     */
    public function pay(Request $request, Booking $booking, BookingPaymentService $paymentService)
    {
        $this->authorizeOwnBooking($request, $booking);

        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        $result = $paymentService->initiate($booking, $validated['payment_method']);

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

    private function authorizeOwnBooking(Request $request, Booking $booking): void
    {
        abort_unless(
            $booking->customer?->customer_account_id === $request->user()->id,
            403,
            'This booking does not belong to you.',
        );
    }
}
