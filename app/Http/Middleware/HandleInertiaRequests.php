<?php

namespace App\Http\Middleware;

use App\Models\Menu;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'appName' => config('app.name', 'Laravel'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'is_active']),
                'permissions' => $request->user() ? $request->user()->getCachedPermissions() : [],
                'canAccessBackend' => $request->user()?->canAccessBackend() ?? false,
            ],
            'tinymce_api_key' => $request->user()?->canAccessBackend() ? config('services.tinymce.api_key') : null,
            'settings' => app(SettingsService::class)->getPublicSettings(),
            'aiChatEnabled' => (bool) app(SettingsService::class)->get('ai.chat_enabled', true),
            'menus' => Cache::remember('active_menus', 300, fn () => Menu::active()->get()->keyBy('slug')),
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
