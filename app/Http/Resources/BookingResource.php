<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
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
            'venue_id' => $this->venue_id,
            'venue' => new VenueResource($this->whenLoaded('venue')),
            'billiard_table_id' => $this->billiard_table_id,
            'billiard_table' => new BilliardTableResource($this->whenLoaded('billiardTable')),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'user_id' => $this->user_id,
            'created_by' => new UserResource($this->whenLoaded('user')),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'total_price' => $this->total_price,
            'promotion_id' => $this->promotion_id,
            'promotion' => new PromotionResource($this->whenLoaded('promotion')),
            'discount_amount' => $this->discount_amount,
            'service_fee' => $this->service_fee,
            'payable_amount' => $this->payableAmount(),
            'review' => $this->whenLoaded('review', fn () => $this->review ? new CustomerReviewResource($this->review) : null),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
