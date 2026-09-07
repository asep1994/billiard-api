<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing review shape for the customer app's "Ulasan" tab. Deliberately
 * narrower than ReviewResource, which nests the full CustomerResource
 * (phone, email) and BookingResource (price, payment status) - neither of
 * which should be exposed to anonymous visitors browsing a venue's reviews.
 */
class CustomerReviewResource extends JsonResource
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
            'rating' => $this->rating,
            'comment' => $this->comment,
            'customer_name' => $this->customer?->name,
            'created_at' => $this->created_at,
        ];
    }
}
