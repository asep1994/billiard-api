<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Places API (New)'s Text Search endpoint, used to find
 * billiard venues near a customer that haven't signed up on the platform yet
 * - see NearbyPlaceController. There's no dedicated "billiard hall" place
 * type in Google's taxonomy, so a text query is used instead of a type
 * filter.
 */
class GooglePlacesService
{
    private const SEARCH_TEXT_URL = 'https://places.googleapis.com/v1/places:searchText';

    private const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.location,places.rating,places.userRatingCount';

    public function __construct(private readonly string $apiKey) {}

    /**
     * Search for billiard-related places within `radiusMeters` of the given
     * coordinates.
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     address: string|null,
     *     latitude: float|null,
     *     longitude: float|null,
     *     rating: float|null,
     *     rating_count: int|null,
     * }>
     */
    public function searchBilliardVenues(float $lat, float $lng, int $radiusMeters = 8000, int $maxResults = 20): array
    {
        $response = Http::withHeaders([
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => self::FIELD_MASK,
        ])->post(self::SEARCH_TEXT_URL, [
            'textQuery' => 'billiard',
            'maxResultCount' => $maxResults,
            'locationBias' => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => (float) $radiusMeters,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Google Places search failed: {$response->status()} {$response->body()}");
        }

        return collect($response->json('places', []))
            ->map(fn (array $place) => [
                'id' => $place['id'],
                'name' => $place['displayName']['text'] ?? 'Tanpa nama',
                'address' => $place['formattedAddress'] ?? null,
                'latitude' => $place['location']['latitude'] ?? null,
                'longitude' => $place['location']['longitude'] ?? null,
                'rating' => $place['rating'] ?? null,
                'rating_count' => $place['userRatingCount'] ?? null,
            ])
            ->all();
    }
}
