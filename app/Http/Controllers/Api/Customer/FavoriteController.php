<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Favorite;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FavoriteController extends Controller
{
    /**
     * List the authenticated customer's favorited venues.
     */
    public function index(Request $request)
    {
        $venues = Venue::query()
            ->whereHas('favoritedBy', fn ($query) => $query->where('customer_account_id', $request->user()->id))
            ->where('status', Status::Active)
            ->with('vendor')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withMin('billiardTables as tables_min_hourly_rate', 'hourly_rate')
            ->get()
            ->each->setAttribute('is_favorited', true);

        return VenueResource::collection($venues);
    }

    /**
     * Favorite a venue for the authenticated customer.
     */
    public function store(Request $request, Venue $venue)
    {
        Favorite::firstOrCreate([
            'customer_account_id' => $request->user()->id,
            'venue_id' => $venue->id,
        ]);

        return response()->noContent(Response::HTTP_CREATED);
    }

    /**
     * Remove a venue from the authenticated customer's favorites.
     */
    public function destroy(Request $request, Venue $venue)
    {
        Favorite::where('customer_account_id', $request->user()->id)
            ->where('venue_id', $venue->id)
            ->delete();

        return response()->noContent();
    }
}
