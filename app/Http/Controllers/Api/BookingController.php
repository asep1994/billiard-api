<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\BilliardTable;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            $bookings->with(['venue', 'billiardTable', 'customer'])->latest('start_time')->paginate($perPage)
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

        $booking = Booking::create($data)->refresh();

        return (new BookingResource($booking))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Booking $booking)
    {
        return new BookingResource($booking->load(['venue', 'billiardTable', 'customer', 'user']));
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

        $booking->update($data);

        return new BookingResource($booking);
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
