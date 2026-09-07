<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerReviewResource;
use App\Models\Venue;
use Illuminate\Http\Request;

/**
 * Public listing of a venue's reviews for the "Ulasan" tab - no
 * authentication required, same as browsing venues.
 */
class ReviewController extends Controller
{
    public function index(Request $request, Venue $venue)
    {
        abort_unless($venue->status === Status::Active, 404);

        $perPage = min($request->integer('per_page', 15), 100);

        $reviews = $venue->reviews()
            ->with('customer')
            ->latest()
            ->paginate($perPage);

        return CustomerReviewResource::collection($reviews);
    }
}
