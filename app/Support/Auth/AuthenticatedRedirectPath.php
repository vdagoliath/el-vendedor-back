<?php

namespace App\Support\Auth;

use App\Models\User;

class AuthenticatedRedirectPath
{
    /**
     * Resolve the post-authentication redirect path for the given user.
     */
    public function for(?User $user): string
    {
        if ($user?->canAccessBackoffice()) {
            return '/backoffice/businesses';
        }

        return '/settings/profile';
    }
}
