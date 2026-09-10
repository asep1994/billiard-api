<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\GooglePlacesService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Surfaces billiard venues found via Google Places that aren't partnered
 * with the platform yet, for the Jelajah tab's "ditemukan di sekitar, belum
 * gabung" section - purely informational (no booking), feeding the
 * "Ajak Gabung" lead-gen flow in PartnerLeadController.
 */
class NearbyPlaceController extends Controller
{
    public function index(Request $request, GooglePlacesService $places)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $results = $places->searchBilliardVenues($validated['lat'], $validated['lng']);

        $partnered = Venue::where('status', Status::Active)
            ->get(['name', 'latitude', 'longitude'])
            ->map(fn (Venue $venue) => [
                'name' => $this->normalizeName($venue->name),
                'lat' => $venue->latitude,
                'lng' => $venue->longitude,
            ]);

        $filtered = collect($results)
            ->reject(fn (array $place) => $this->isAlreadyPartnered($place, $partnered))
            ->values();

        return response()->json(['data' => $filtered]);
    }

    /**
     * A Google result counts as "already partnered" if its name closely
     * matches one of ours, or it sits within ~150m of one of our venues -
     * either signal alone is enough (name matches survive minor address
     * differences Google records; the distance check catches venues that
     * registered under a different display name).
     */
    private function isAlreadyPartnered(array $place, Collection $partnered): bool
    {
        $placeName = $this->normalizeName($place['name']);

        foreach ($partnered as $venue) {
            if ($venue['name'] !== '' && (str_contains($placeName, $venue['name']) || str_contains($venue['name'], $placeName))) {
                return true;
            }

            if ($venue['lat'] !== null && $venue['lng'] !== null && $place['latitude'] !== null && $place['longitude'] !== null) {
                $distanceKm = $this->haversineKm(
                    (float) $venue['lat'],
                    (float) $venue['lng'],
                    (float) $place['latitude'],
                    (float) $place['longitude'],
                );

                if ($distanceKm <= 0.15) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower($name)));
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
