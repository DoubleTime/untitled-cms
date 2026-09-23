<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name', 'Unysis Marketplace'),
            'appVersion' => config('app.version'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'is_active']),
                'permissions' => $request->user() ? $request->user()->getCachedPermissions() : [],
                'canAccessBackend' => $request->user()?->canAccessBackend() ?? false,
            ],
            'settings' => app(SettingsService::class)->getPublicSettings(),
            'passwordRulesString' => Password::defaults()->toPasswordRulesString(),
            // Controllers flash success/error with redirect()->with(...); the frontend
            // surfaces these as toasts (see resources/js/hooks/use-flash-toast.ts).
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
