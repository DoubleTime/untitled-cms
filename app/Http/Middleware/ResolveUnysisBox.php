<?php

namespace App\Http\Middleware;

use App\Exceptions\Marketplace\UnysisBoxBelongsToAnotherCustomer;
use App\Exceptions\Marketplace\UnysisBoxBlocked;
use App\Models\UnysisBox;
use App\Services\Marketplace\CustomerUserGuard;
use App\Services\Marketplace\UnysisBoxService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the UNYSIS Box behind an authenticated RPA-TOOL request and re-checks that
 * the caller is still allowed in.
 *
 * Every Sanctum token issued by POST /api/v1/login is named after the motherboard
 * UUID it was issued for, so the box is derived from the token and never from
 * request input (docs/adr/0002). Re-checking here — rather than only at login —
 * is what makes blocking a box, deactivating a Customer User or deactivating a
 * Customer take effect immediately instead of when the 30-day token expires.
 *
 * The resolved box is put on the request as the `unysis_box` attribute; download
 * attribution reads it from there.
 */
class ResolveUnysisBox
{
    /** Request attribute holding the resolved UNYSIS Box. */
    public const ATTRIBUTE = 'unysis_box';

    public const ACCOUNT_REFUSED = 'This account cannot access the Marketplace API.';

    public const BOX_UNKNOWN = 'This UNYSIS Box is no longer registered. Sign in again.';

    public function __construct(
        private UnysisBoxService $boxes,
        private CustomerUserGuard $customerUsers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        // The account may have been deactivated, unlinked from its Customer or
        // promoted to a Team Member since the token was issued.
        if (! $user->is_active || ! $user->isCustomerUser() || ! $this->customerUsers->isRpaToolOnly($user)) {
            abort(403, self::ACCOUNT_REFUSED);
        }

        $customer = $user->customer;

        if ($customer === null || ! $customer->is_active) {
            abort(403, self::ACCOUNT_REFUSED);
        }

        // Guest/session callers (Sanctum's TransientToken) carry no box.
        $token = $user->currentAccessToken();
        $uuid = $token instanceof Model
            ? UnysisBoxService::normaliseUuid((string) $token->getAttribute('name'))
            : '';

        $box = $uuid === '' ? null : UnysisBox::query()->where('motherboard_uuid', $uuid)->first();

        if ($box === null) {
            abort(403, self::BOX_UNKNOWN);
        }

        try {
            $this->boxes->assertUsable($box, $customer);
        } catch (UnysisBoxBelongsToAnotherCustomer|UnysisBoxBlocked $e) {
            abort(403, $e->getMessage());
        }

        $request->attributes->set(self::ATTRIBUTE, $box);

        $this->boxes->touch($box, $request->ip());

        return $next($request);
    }
}
