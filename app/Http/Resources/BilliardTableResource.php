<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BilliardTableResource extends JsonResource
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
            'venue_id' => $this->venue_id,
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'name' => $this->name,
            'type' => $this->type,
            'hourly_rate' => $this->hourly_rate,
            // JsonResource::removeMissingValues() re-indexes any array whose
            // keys are all numeric (it assumes that means "list"), which
            // would otherwise silently turn {"1":60000,"2":110000} - a
            // deliberate hour-keyed map - into [60000, 110000]. Casting the
            // keys to strings keeps them intact through that step. When
            // there are no packages, cast to an empty object rather than an
            // empty array too - json_encode can't otherwise tell an empty
            // map apart from an empty list, so a client would see this
            // field's JSON type flip between {} and [] depending on data.
            'duration_prices' => $this->duration_prices
                ? collect($this->duration_prices)->mapWithKeys(fn ($price, $hours) => [(string) $hours => $price])
                : (object) [],
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
