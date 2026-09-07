<?php

namespace App\Models;

use App\Enums\TableStatus;
use App\Enums\TableType;
use App\Policies\BilliardTablePolicy;
use Database\Factories\BilliardTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['venue_id', 'name', 'type', 'hourly_rate', 'duration_prices', 'status'])]
#[UseFactory(BilliardTableFactory::class)]
#[UsePolicy(BilliardTablePolicy::class)]
class BilliardTable extends Model
{
    /** @use HasFactory<BilliardTableFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TableType::class,
            'hourly_rate' => 'decimal:2',
            'duration_prices' => 'array',
            'status' => TableStatus::class,
        ];
    }

    /**
     * The flat package price for a whole-hour duration, if the vendor set
     * one - otherwise null, meaning the caller should fall back to
     * hourly_rate * hours.
     */
    public function priceForHours(int $hours): ?float
    {
        $price = ($this->duration_prices ?? [])[$hours] ?? null;

        return $price !== null ? (float) $price : null;
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
