<?php

namespace App\Services\Marketplace;

use App\Models\User;

/**
 * The single test for "this account exists only for RPA-TOOL".
 *
 * Per docs/adr/0002 a Customer User reaches the catalogue through RPA-TOOL and
 * must never hold a web session, while the API refuses everyone else — the two
 * rules are mirror images of one test, so the test lives here rather than on the
 * web LoginRequest that happened to need it first. Its callers are the web login
 * (App\Http\Requests\Auth\LoginRequest), Socialite
 * (App\Http\Controllers\Auth\SocialAuthController), the API login
 * (App\Http\Controllers\Api\V1\AuthController) and the per-request re-check in
 * App\Http\Middleware\ResolveUnysisBox.
 */
class CustomerUserGuard
{
    /**
     * Shown to a Customer User who tries the web login. Deliberately says nothing
     * about whether the password was right.
     */
    public const RPA_TOOL_ONLY_MESSAGE = 'This account can only be used from RPA-TOOL.';

    /**
     * True when this account exists only for RPA-TOOL — it is linked to a Customer,
     * or it carries the `customer` role and no role granting backend access.
     */
    public function isRpaToolOnly(User $user): bool
    {
        if ($user->isCustomerUser()) {
            return true;
        }

        return $user->hasRole('customer') && ! $user->canAccessBackend();
    }
}
