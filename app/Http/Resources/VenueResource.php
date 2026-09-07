<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VenueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address,
            'city' => $this->city,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'distance_km' => $this->distance_km !== null ? round((float) $this->distance_km, 1) : null,
            'photo_url' => $this->photoUrl(),
            'description' => $this->description,
            'facilities' => $this->facilities ?? [],
            'rating' => $this->reviews_avg_rating !== null ? round((float) $this->reviews_avg_rating, 1) : null,
            'reviews_count' => $this->reviews_count !== null ? (int) $this->reviews_count : 0,
            'price_from' => $this->tables_min_hourly_rate !== null ? (float) $this->tables_min_hourly_rate : null,
            'is_favorited' => (bool) ($this->is_favorited ?? false),
            'phone' => $this->phone,
            'opening_time' => $this->opening_time?->format('H:i'),
            'closing_time' => $this->closing_time?->format('H:i'),
            'status' => $this->status,
            'tables' => BilliardTableResource::collection($this->whenLoaded('billiardTables')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
