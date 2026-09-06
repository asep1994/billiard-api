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
use App\Notifications\NewBookingCreated;
use Carbon\Carbon;
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

        $perPage = min($request->integer('per_page', 15), 100);

        return BookingResource::collection(
            $bookings->with(['venue', 'billiardTable', 'customer', 'promotion'])->latest('start_time')->paginate($perPage)
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
        $data['total_price'] = $this->calculateTotalPrice(
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

            $data['total_price'] = $this->calculateTotalPrice(
                $table,
                $data['start_time'] ?? $booking->start_time,
                $data['end_time'] ?? $booking->end_time,
            );
        }

        $wasCancelled = $booking->status !== BookingStatus::Cancelled
            && ($data['status'] ?? null) === BookingStatus::Cancelled->value;

        $booking->update($data);

        if ($wasCancelled) {
            ActivityLog::record(
                ActivityAction::Cancelled,
                'booking',
                $booking->id,
                "{$request->user()->name} membatalkan booking BK-".str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT),
                $booking->vendor_id,
            );
        }

        return new BookingResource($booking->load(['venue', 'billiardTable', 'customer', 'user', 'promotion']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Booking $booking)
    {
        $booking->delete();

        return response()->noContent();
    }

    /**
     * Calculate the total price for a booking based on the table's hourly rate.
     */
    private function calculateTotalPrice(BilliardTable $table, string|Carbon $start, string|Carbon $end): float
    {
        $hours = Carbon::parse($start)->diffInMinutes(Carbon::parse($end)) / 60;

        return round((float) $table->hourly_rate * $hours, 2);
    }
}
