<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Policies\BookingPolicy;
use Carbon\Carbon;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'vendor_id',
    'venue_id',
    'billiard_table_id',
    'customer_id',
    'user_id',
    'promotion_id',
    'start_time',
    'end_time',
    'status',
    'payment_status',
    'total_price',
    'discount_amount',
    'service_fee',
    'notes',
])]
#[UseFactory(BookingFactory::class)]
#[UsePolicy(BookingPolicy::class)]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
            'total_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'reminder_1h_sent_at' => 'datetime',
            'reminder_30m_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return BelongsTo<BilliardTable, $this>
     */
    public function billiardTable(): BelongsTo
    {
        return $this->belongsTo(BilliardTable::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Calculate the total price for a booking. Whole-hour durations use the
     * table's flat package price when the vendor set one (e.g. a discounted
     * 2-jam rate); any other duration falls back to hourly_rate * hours.
     */
    public static function calculateTotalPrice(BilliardTable $table, string|Carbon $start, string|Carbon $end): float
    {
        $minutes = Carbon::parse($start)->diffInMinutes(Carbon::parse($end));
        $hours = $minutes / 60;

        if ($minutes % 60 === 0) {
            $packagePrice = $table->priceForHours((int) $hours);

            if ($packagePrice !== null) {
                return round($packagePrice, 2);
            }
        }

        return round((float) $table->hourly_rate * $hours, 2);
    }

    /**
     * The actual amount owed: table price, minus any promo discount, plus
     * the platform service fee. The single source of truth for this sum -
     * BookingResource and BookingPaymentService both use it, so the amount
     * shown to the customer and the amount actually charged via Duitku can
     * never drift apart.
     */
    public function payableAmount(): float
    {
        return round((float) $this->total_price - (float) $this->discount_amount + (float) $this->service_fee, 2);
    }

    /**
     * Determine whether a table already has a non-cancelled booking overlapping the given time range.
     */
    public static function overlapsExisting(int $billiardTableId, string|Carbon $start, string|Carbon $end): bool
    {
        return self::query()
            ->where('billiard_table_id', $billiardTableId)
            ->where('status', '!=', BookingStatus::Cancelled)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }
}
