<?php

namespace App\Policies;

use App\Models\PartnerLead;
use App\Models\User;

/**
 * Partner leads are a platform-wide business-dev concern (venues that
 * haven't signed up yet aren't tied to any vendor), so managing them is
 * super-admin-only, same as banners.
 */
class PartnerLeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, PartnerLead $lead): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, PartnerLead $lead): bool
    {
        return $user->isSuperAdmin();
    }
}
