<?php

namespace App\Policies;

use App\Models\Banner;
use App\Models\User;

/**
 * Banners are platform-wide (shown to every customer regardless of vendor),
 * so managing them is a super-admin-only concern.
 */
class BannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Banner $banner): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Banner $banner): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Banner $banner): bool
    {
        return $user->isSuperAdmin();
    }
}
