<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Marketplace\CustomerUserGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'github'];

    public function __construct(private CustomerUserGuard $customerUsers) {}

    public function redirect(string $provider): RedirectResponse
    {
        $this->abortIfUnsupported($provider);
        $this->abortIfDisabled($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->abortIfUnsupported($provider);
        $this->abortIfDisabled($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            // Log for debugging (InvalidStateException = CSRF mismatch; others = config/network)
            Log::warning('Social login exception', [
                'provider' => $provider,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'Social login failed. Please try again.',
            ]);
        }

        $email = $socialUser->getEmail();

        if (! $email) {
            return redirect()->route('login')->withErrors([
                'email' => 'No email address returned from '.ucfirst($provider).'. Please use a different login method.',
            ]);
        }

        // Single query including soft-deleted records to avoid a two-query race window.
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user?->trashed()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account no longer exists. Please contact an administrator.',
            ]);
        }

        // A Customer User linked by email is refused exactly as on the password
        // login — they belong to RPA-TOOL and never get a web session. Checked
        // before the provider is linked so no social account is attached to them.
        if ($user && $this->customerUsers->isRpaToolOnly($user)) {
            return redirect()->route('login')->withErrors([
                'email' => CustomerUserGuard::RPA_TOOL_ONLY_MESSAGE,
            ]);
        }

        if (! $user) {
            // Respect the registration gate for new social users
            if (! Setting::get('auth.registration_enabled', false)) {
                return redirect()->route('login')->withErrors([
                    'email' => 'Registration is currently closed. Contact an administrator to get access.',
                ]);
            }

            try {
                $user = $this->createSocialUser($socialUser, $provider);
            } catch (\Exception) {
                // Handles duplicate-key race condition on concurrent requests.
                // Use withTrashed() for consistency with the main path.
                $user = User::withTrashed()->where('email', $email)->first();

                if (! $user || $user->trashed()) {
                    return redirect()->route('login')->withErrors([
                        'email' => 'Could not create your account. Please try again.',
                    ]);
                }

                $this->linkProviderIfNew($user, $socialUser, $provider);
            }
        } else {
            $this->linkProviderIfNew($user, $socialUser, $provider);
        }

        // Belt and braces: the race-recovery path above can hand back a different
        // record than the one checked earlier.
        if ($this->customerUsers->isRpaToolOnly($user)) {
            return redirect()->route('login')->withErrors([
                'email' => CustomerUserGuard::RPA_TOOL_ONLY_MESSAGE,
            ]);
        }

        // Single authoritative is_active check — applied to every code path before login.
        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Please contact an administrator.',
            ]);
        }

        // Regenerate session to prevent session fixation attacks.
        $request->session()->regenerate();

        Auth::login($user, remember: true);

        ActivityLogger::log('social_login', "User logged in via {$provider}: {$user->email}", $user);

        return redirect($this->safeIntendedUrl());
    }

    /**
     * Return the intended redirect URL only if it belongs to this app's host,
     * preventing open redirect attacks via a crafted `url.intended` session value.
     * A str_starts_with() prefix check is bypassable (e.g. app.url + ".evil.com"),
     * so we compare parsed hosts instead.
     */
    private function safeIntendedUrl(): string
    {
        $user = auth()->user();

        if (! $user) {
            return route('login');
        }

        // Non-admin users always go to their profile — never follow a stored /admin/* URL.
        if (! $user->canAccessBackend()) {
            session()->forget('url.intended');

            return url('/');
        }

        // Admin users: honour the intended URL only if it belongs to this app's host.
        // A str_starts_with() prefix check is bypassable (e.g. app.url + ".evil.com"),
        // so we compare parsed hosts instead.
        $intended = session()->pull('url.intended', '');
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $intendedHost = parse_url($intended, PHP_URL_HOST);

        if ($intendedHost && $intendedHost === $appHost) {
            return $intended;
        }

        return route('admin.dashboard');
    }

    private function createSocialUser(\Laravel\Socialite\Contracts\User $socialUser, string $provider): User
    {
        $user = User::create([
            'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'User',
            'email' => $socialUser->getEmail(),
            'password' => null,
            'is_active' => true,
            'email_verified_at' => now(),
            'social_accounts' => [[
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
            ]],
        ]);

        $userRole = Role::where('slug', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        ActivityLogger::log('registered', "New user registered via {$provider}: {$user->email}", $user);

        return $user;
    }

    private function linkProviderIfNew(User $user, \Laravel\Socialite\Contracts\User $socialUser, string $provider): void
    {
        $accounts = $user->social_accounts;

        $alreadyLinked = collect($accounts)->contains(
            fn ($account) => $account['provider'] === $provider && $account['provider_id'] === $socialUser->getId()
        );

        if (! $alreadyLinked) {
            $accounts[] = [
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
            ];

            $user->update([
                'social_accounts' => $accounts,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
        }
    }

    private function abortIfUnsupported(string $provider): void
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, strict: true)) {
            abort(404);
        }
    }

    private function abortIfDisabled(string $provider): void
    {
        $settingKey = "social_login_{$provider}_enabled";

        if (! Setting::get($settingKey, false)) {
            abort(403, ucfirst($provider).' login is not enabled.');
        }
    }
}
