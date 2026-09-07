<?php

namespace App\Models;

use Database\Factories\CustomerAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A platform-wide customer identity used by the customer-facing (Flutter) app.
 *
 * One account can book at any vendor; per-vendor `Customer` records (used by
 * staff for walk-ins and reporting) are found-or-created and linked back to
 * this account the first time it books at that vendor.
 */
#[Fillable(['name', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(CustomerAccountFactory::class)]
class CustomerAccount extends Authenticatable
{
    /** @use HasFactory<CustomerAccountFactory> */
    use HasApiTokens, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * @return HasMany<CustomerDeviceToken, $this>
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(CustomerDeviceToken::class);
    }
}
