<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Review $review): bool
    {
        return $user->isSuperAdmin() || $user->belongsToVendor($review->vendor_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isVendorAdmin() || $user->isStaff();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Review $review): bool
    {
        return $user->isSuperAdmin() || ($user->isVendorAdmin() && $user->belongsToVendor($review->vendor_id));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->isSuperAdmin() || ($user->isVendorAdmin() && $user->belongsToVendor($review->vendor_id));
    }
}
