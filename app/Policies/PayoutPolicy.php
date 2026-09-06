<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

class PayoutPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isVendorAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Payout $payout): bool
    {
        return $user->isSuperAdmin() || $user->belongsToVendor($payout->vendor_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
