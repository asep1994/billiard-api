<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReviewController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Review::class, 'review');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $reviews = $request->user()->isSuperAdmin()
            ? Review::query()
            : Review::where('vendor_id', $request->user()->vendor_id);

        if ($request->filled('venue_id')) {
            $reviews->where('venue_id', $request->integer('venue_id'));
        }

        $perPage = min($request->integer('per_page', 15), 100);

        return ReviewResource::collection(
            $reviews->with(['customer', 'venue', 'booking'])->latest()->paginate($perPage)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreReviewRequest $request)
    {
        $booking = Booking::findOrFail($request->validated('booking_id'));

        abort_unless(
            $booking->status === BookingStatus::Completed,
            422,
            'Hanya booking yang sudah selesai yang bisa diberi ulasan.',
        );

        $review = Review::create([
            'vendor_id' => $booking->vendor_id,
            'venue_id' => $booking->venue_id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'rating' => $request->validated('rating'),
            'comment' => $request->validated('comment'),
        ])->load(['customer', 'venue', 'booking']);

        return (new ReviewResource($review))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateReviewRequest $request, Review $review)
    {
        $review->update($request->validated());

        return new ReviewResource($review->load(['customer', 'venue', 'booking']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Review $review)
    {
        $review->delete();

        return response()->noContent();
    }
}
