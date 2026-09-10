<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Policies\PartnerLeadPolicy;
use Database\Factories\PartnerLeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billiard venue found nearby via Google Places that hasn't signed up as a
 * vendor yet - created when a customer taps "Ajak Gabung" in the Jelajah
 * tab, so the platform's business-dev team can follow up.
 */
#[Fillable([
    'customer_account_id', 'google_place_id', 'name', 'address',
    'latitude', 'longitude', 'google_rating', 'google_rating_count', 'status', 'notes',
])]
#[UseFactory(PartnerLeadFactory::class)]
#[UsePolicy(PartnerLeadPolicy::class)]
class PartnerLead extends Model
{
    /** @use HasFactory<PartnerLeadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'google_rating' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
