<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\ActivityLog;
use App\Models\BilliardTable;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\User;
use App\Notifications\Customer\BookingCancelled as CustomerBookingCancelled;
use App\Notifications\Customer\BookingConfirmed as CustomerBookingConfirmed;
use App\Notifications\NewBookingCreated;
use App\Services\BookingReminderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

class BookingController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Booking::class, 'booking');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $bookings = $request->user()->isSuperAdmin()
            ? Booking::query()
            : Booking::where('vendor_id', $request->user()->vendor_id);

        if ($request->filled('venue_id')) {
            $bookings->where('venue_id', $request->integer('venue_id'));
        }

        $perPage = min($request->integer('per_page', 15), 100);

        return BookingResource::collection(
            $bookings->with(['venue', 'billiardTable', 'customer', 'promotion'])->latest()->paginate($perPage)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookingRequest $request)
    {
        $data = $request->validated();
        $data['vendor_id'] = $request->user()->isSuperAdmin() ? $data['vendor_id'] : $request->user()->vendor_id;
        $data['user_id'] ??= $request->user()->id;
        $data['total_price'] = Booking::calculateTotalPrice(
            BilliardTable::findOrFail($data['billiard_table_id']),
            $data['start_time'],
            $data['end_time'],
        );

        $promotion = null;

        if (! empty($data['promo_code'])) {
            $promotion = Promotion::where('vendor_id', $data['vendor_id'])
                ->where('code', $data['promo_code'])
                ->first();
        }

        unset($data['promo_code']);

        if ($promotion) {
            $data['promotion_id'] = $promotion->id;
            $data['discount_amount'] = $promotion->calculateDiscount($data['total_price']);
        }

        $booking = Booking::create($data)->refresh()->load(['venue', 'billiardTable', 'customer', 'promotion']);

        $promotion?->increment('times_used');

        ActivityLog::record(
            ActivityAction::Created,
            'booking',
            $booking->id,
            "{$request->user()->name} membuat booking BK-".str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)." untuk {$booking->customer->name}",
            $booking->vendor_id,
        );

        $vendorAdmins = User::where('vendor_id', $booking->vendor_id)
            ->where('role', UserRole::VendorAdmin)
            ->where('id', '!=', $request->user()->id)
            ->get();

        Notification::send($vendorAdmins, new NewBookingCreated($booking));

        return (new BookingResource($booking))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Booking $booking)
    {
        return new BookingResource($booking->load(['venue', 'billiardTable', 'customer', 'user', 'promotion']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBookingRequest $request, Booking $booking)
    {
        $data = $request->validated();

        if (array_intersect(['billiard_table_id', 'start_time', 'end_time'], array_keys($data))) {
            $table = isset($data['billiard_table_id'])
                ? BilliardTable::findOrFail($data['billiard_table_id'])
                : $booking->billiardTable;

            $data['total_price'] = Booking::calculateTotalPrice(
                $table,
                $data['start_time'] ?? $booking->start_time,
                $data['end_time'] ?? $booking->end_time,
            );
        }

        $wasCancelled = $booking->status !== BookingStatus::Cancelled
            && ($data['status'] ?? null) === BookingStatus::Cancelled->value;

        $wasConfirmed = $booking->status !== BookingStatus::Confirmed
            && ($data['status'] ?? null) === BookingStatus::Confirmed->value;

        $booking->update($data);
        $booking->load(['venue', 'billiardTable', 'customer.customerAccount', 'user', 'promotion']);

        if ($wasCancelled) {
            ActivityLog::record(
                ActivityAction::Cancelled,
                'booking',
                $booking->id,
                "{$request->user()->name} membatalkan booking BK-".str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT),
                $booking->vendor_id,
            );

            if ($account = $booking->customer?->customerAccount) {
                Notification::send($account, new CustomerBookingCancelled($booking));
            }
        }

        if ($wasConfirmed && ($account = $booking->customer?->customerAccount)) {
            Notification::send($account, new CustomerBookingConfirmed($booking));
        }

        return new BookingResource($booking);
    }

    /**
     * Manually trigger the "pay up" / "come play" reminder check for the
     * requesting admin's own vendor (or a specific venue of theirs), rather
     * than waiting on a scheduler.
     */
    public function sendReminders(Request $request, BookingReminderService $reminders)
    {
        $vendorId = $request->user()->isSuperAdmin() ? $request->integer('vendor_id') ?: null : $request->user()->vendor_id;

        $sent = $reminders->sendDueReminders($vendorId, $request->integer('venue_id') ?: null);

        return response()->json(['sent' => $sent]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking)
    {
        $booking->delete();

        return response()->noContent();
    }
}
