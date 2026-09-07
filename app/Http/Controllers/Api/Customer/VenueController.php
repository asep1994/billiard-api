<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\BookingStatus;
use App\Enums\Status;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BilliardTableResource;
use App\Http\Resources\VenueResource;
use App\Models\BilliardTable;
use App\Models\CustomerAccount;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

/**
 * Public venue browsing for the customer app - no authentication required,
 * since guests should be able to browse before creating an account.
 */
class VenueController extends Controller
{
    /**
     * Display a listing of active venues. When `lat`/`lng` are given, results
     * are sorted nearest-first and carry a `distance_km`; venues without
     * coordinates are excluded from that sort since "nearest" is meaningless
     * without a location to compare against.
     */
    public function index(Request $request)
    {
        $venues = Venue::where('status', Status::Active)
            ->with('vendor')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->withMin('billiardTables as tables_min_hourly_rate', 'hourly_rate');

        if ($request->filled('city')) {
            $venues->where('city', 'like', '%'.$request->string('city').'%');
        }

        if ($request->filled('search')) {
            $venues->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->boolean('open_now')) {
            $now = Date::now()->format('H:i:s');
            $venues->whereNotNull('opening_time')
                ->whereNotNull('closing_time')
                ->whereTime('opening_time', '<=', $now)
                ->whereTime('closing_time', '>=', $now);
        }

        if ($request->filled('lat') || $request->filled('lng')) {
            $validated = $request->validate([
                'lat' => ['required', 'numeric', 'between:-90,90'],
                'lng' => ['required', 'numeric', 'between:-180,180'],
            ]);

            // Haversine distance in kilometres; least(1, ...) guards acos()
            // against floating-point rounding pushing the input just past 1
            // when the customer is (almost) exactly at the venue.
            $venues->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->selectRaw('venues.*')
                ->selectRaw(
                    '(6371 * acos(least(1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))) as distance_km',
                    [$validated['lat'], $validated['lng'], $validated['lat']]
                )
                ->orderBy('distance_km');
        }

        if ($request->query('sort') === 'rating') {
            // Postgres defaults DESC order to NULLS FIRST, which would rank
            // unreviewed venues above every rated one - pin nulls to the end.
            $venues->orderByRaw('reviews_avg_rating DESC NULLS LAST');
        }

        $perPage = min($request->integer('per_page', 15), 100);
        $paginated = $venues->paginate($perPage);
        $this->attachFavoriteStatus(collect($paginated->items()));

        return VenueResource::collection($paginated);
    }

    /**
     * Mark each venue as favorited or not for the currently authenticated
     * customer, if any. Browsing is public, so this is best-effort: guests
     * simply see `is_favorited: false` on every venue.
     *
     * @param  Collection<int, Venue>  $venues
     */
    private function attachFavoriteStatus($venues): void
    {
        $customer = Auth::guard('sanctum')->user();

        if (! $customer instanceof CustomerAccount || $venues->isEmpty()) {
            return;
        }

        $favoritedVenueIds = $customer->favorites()
            ->whereIn('venue_id', $venues->pluck('id'))
            ->pluck('venue_id')
            ->all();

        foreach ($venues as $venue) {
            $venue->setAttribute('is_favorited', in_array($venue->id, $favoritedVenueIds, true));
        }
    }

    /**
     * Display a single active venue with its available tables.
     */
    public function show(Venue $venue)
    {
        abort_unless($venue->status === Status::Active, 404);

        $venue->load(['vendor', 'billiardTables' => fn ($query) => $query->where('status', TableStatus::Available)])
            ->loadAvg('reviews', 'rating')
            ->loadCount('reviews')
            ->loadMin('billiardTables as tables_min_hourly_rate', 'hourly_rate');

        $this->attachFavoriteStatus(collect([$venue]));

        return new VenueResource($venue);
    }

    /**
     * List the tables in a venue that are free for the given time range.
     */
    public function availableTables(Request $request, Venue $venue)
    {
        abort_unless($venue->status === Status::Active, 404);

        $validated = $request->validate([
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
        ]);

        $tables = BilliardTable::where('venue_id', $venue->id)
            ->where('status', TableStatus::Available)
            ->whereDoesntHave('bookings', function ($query) use ($validated) {
                $query->where('status', '!=', BookingStatus::Cancelled)
                    ->where('start_time', '<', $validated['end_time'])
                    ->where('end_time', '>', $validated['start_time']);
            })
            ->get();

        return BilliardTableResource::collection($tables);
    }
}
