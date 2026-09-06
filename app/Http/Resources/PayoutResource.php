<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutResource extends JsonResource
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
            'user_id' => $this->user_id,
            'recorded_by' => new UserResource($this->whenLoaded('user')),
            'amount' => $this->amount,
            'note' => $this->note,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
