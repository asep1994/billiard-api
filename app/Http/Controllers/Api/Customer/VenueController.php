<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\BookingStatus;
use App\Enums\Status;
use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BilliardTableResource;
use App\Http\Resources\VenueResource;
use App\Models\BilliardTable;
use App\Models\Venue;
use Illuminate\Http\Request;

/**
 * Public venue browsing for the customer app - no authentication required,
 * since guests should be able to browse before creating an account.
 */
class VenueController extends Controller
{
    /**
     * Display a listing of active venues.
     */
    public function index(Request $request)
    {
        $venues = Venue::where('status', Status::Active)->with('vendor');

        if ($request->filled('city')) {
            $venues->where('city', 'like', '%'.$request->string('city').'%');
        }

        if ($request->filled('search')) {
            $venues->where('name', 'like', '%'.$request->string('search').'%');
        }

        $perPage = min($request->integer('per_page', 15), 100);

        return VenueResource::collection($venues->paginate($perPage));
    }

    /**
     * Display a single active venue with its available tables.
     */
    public function show(Venue $venue)
    {
        abort_unless($venue->status === Status::Active, 404);

        return new VenueResource(
            $venue->load(['vendor', 'billiardTables' => fn ($query) => $query->where('status', TableStatus::Available)])
        );
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
