<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketplace\StoreCustomerUserRequest;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Customer User management, nested under a Customer.
 *
 * A Customer User signs in only through RPA-TOOL (docs/adr/0002). They are given
 * the `customer` role and nothing else, so they have no backend access, and the
 * web login refuses them outright — see App\Http\Requests\Auth\LoginRequest.
 *
 * Every action here is an edit of the owning Customer, so all of them are gated
 * on `customers.edit` via CustomerPolicy::update().
 */
class CustomerUserController extends Controller
{
    /**
     * Create a Customer User under this Customer.
     *
     * With no password supplied the account is created with an unguessable one
     * and a password reset link is sent so the Customer User sets their own.
     */
    public function store(StoreCustomerUserRequest $request, Customer $customer)
    {
        $validated = $request->validated();

        $customerRole = Role::where('slug', 'customer')->first();

        if (! $customerRole) {
            return redirect()->route('admin.marketplace.customers.show', $customer)
                ->with('error', 'The customer role is missing. Run the role seeder before creating Customer Users.');
        }

        $invite = empty($validated['password']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'customer_id' => $customer->id,
            'password' => Hash::make($invite ? Str::random(40) : $validated['password']),
            // Customer Users never sign in to the web, so email verification is
            // meaningless for them; mark them verified at creation.
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Only the customer role — never anything carrying backend_access.
        $user->syncRoles([$customerRole->id]);

        if ($invite) {
            Password::sendResetLink(['email' => $user->email]);
        }

        ActivityLogger::log('create', "Created Customer User: {$user->email} for Customer: {$customer->company}", $user);

        return redirect()->route('admin.marketplace.customers.show', $customer)
            ->with('success', $invite
                ? "Customer User created. An invite email was sent to {$user->email}."
                : 'Customer User created successfully.');
    }

    /**
     * Toggle is_active. Deactivating also revokes every API token so an AI Box
     * already holding one cannot keep pulling Revisions.
     */
    public function toggleActive(Request $request, Customer $customer, User $user)
    {
        Gate::authorize('update', $customer);
        $this->ensureBelongsTo($customer, $user);

        $activate = ! $user->is_active;
        $user->update(['is_active' => $activate]);

        if (! $activate) {
            $user->tokens()->delete();
        }

        ActivityLogger::log(
            $activate ? 'activate' : 'deactivate',
            ($activate ? 'Reactivated' : 'Deactivated')." Customer User: {$user->email}",
            $user
        );

        return redirect()->route('admin.marketplace.customers.show', $customer)
            ->with('success', $activate
                ? 'Customer User reactivated.'
                : 'Customer User deactivated and all sessions revoked.');
    }

    /**
     * Revoke every API token this Customer User holds, signing out all of their AI Boxes.
     */
    public function revokeTokens(Request $request, Customer $customer, User $user)
    {
        Gate::authorize('update', $customer);
        $this->ensureBelongsTo($customer, $user);

        $revoked = $user->tokens()->delete();

        ActivityLogger::log('revoke_tokens', "Revoked {$revoked} session(s) for Customer User: {$user->email}", $user);

        return redirect()->route('admin.marketplace.customers.show', $customer)
            ->with('success', "Revoked {$revoked} session(s) for {$user->email}.");
    }

    /**
     * Send the standard password reset email so the Customer User can set a new password.
     */
    public function sendPasswordReset(Request $request, Customer $customer, User $user)
    {
        Gate::authorize('update', $customer);
        $this->ensureBelongsTo($customer, $user);

        Password::sendResetLink(['email' => $user->email]);

        ActivityLogger::log('password_reset_sent', "Sent a password reset to Customer User: {$user->email}", $user);

        return redirect()->route('admin.marketplace.customers.show', $customer)
            ->with('success', "Password reset email sent to {$user->email}.");
    }

    /**
     * A Customer User may only be managed through the Customer that owns them.
     */
    private function ensureBelongsTo(Customer $customer, User $user): void
    {
        abort_unless($user->customer_id === $customer->id, 404);
    }
}
